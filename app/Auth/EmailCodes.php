<?php

declare(strict_types=1);

namespace App\Auth;

use App\Enums\EmailCodePurpose;
use App\Exceptions\EmailCodeNotSent;
use App\Exceptions\EmailCodeRejected;
use App\Exceptions\TooManyEmailCodes;
use App\Models\EmailCode;
use App\Models\User;
use App\Notifications\EmailCodeNotification;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

/**
 * One-time codes emailed to an address: to sign in with it, or to add it to
 * the signed-in user's sign-in methods.
 *
 * A code is six digits and only its hash is stored. It works once, for ten
 * minutes and five tries, and only for the purpose (and the user) it was
 * sent for; sending a new one replaces it. Sending is limited per address,
 * with a pause between codes, and per IP address. Sends to one address, and
 * from one IP address, take turns under cache locks while they check and
 * count the limits and store the code, so two at once can't both get
 * through. What happens is the same whether or not anyone signs in with the
 * address, so sending reveals nothing about accounts.
 *
 * The code is never logged and never part of an exception: parameters that
 * carry it are #[SensitiveParameter], so stack traces leave it out, and a
 * failure to send the email is replaced by an exception of Nexus's own. The
 * email (EmailCodeNotification) is sent at once through the configured
 * mailer, never queued, so no job payload holds it either.
 */
class EmailCodes
{
    public const int LENGTH = 6;

    public const int MINUTES_VALID = 10;

    public const int MAX_TRIES = 5;

    /**
     * How long the same address waits between codes.
     */
    public const int SECONDS_BETWEEN_SENDS = 60;

    /**
     * How long one send may hold the locks for its address and IP address,
     * in seconds; it only counts and stores, so it needs far less.
     */
    private const int LOCK_SECONDS = 10;

    /**
     * How long another send to the same address, or from the same IP
     * address, waits for the locks, in seconds.
     */
    private const int WAIT_SECONDS = 5;

    public function __construct(
        private readonly Request $request,
        private readonly Hasher $hasher,
    ) {}

    /**
     * Whether codes can reach anyone. In production the log and array
     * mailers deliver nothing, so email sign-in is offered only once a real
     * mailer is configured; locally the log mailer is the inbox.
     */
    public static function canBeSent(): bool
    {
        if (! app()->isProduction()) {
            return true;
        }

        $mailer = config('mail.default');
        $transport = is_string($mailer) ? config("mail.mailers.{$mailer}.transport") : null;

        return is_string($transport) && ! in_array($transport, ['log', 'array'], true);
    }

    /**
     * An email address the way Nexus stores and compares it: trimmed and in
     * lower case.
     */
    public static function address(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * Email a new code to the address for a purpose, replacing the code sent
     * before for the same purpose and user.
     *
     * @param  User|null  $user  The signed-in user adding the address, or null to sign in with it.
     *
     * @throws TooManyEmailCodes when the address or this IP address must wait
     * @throws EmailCodeNotSent when the email couldn't be sent
     */
    public function send(string $email, EmailCodePurpose $purpose, ?User $user = null): void
    {
        $email = self::address($email);

        $code = Str::padLeft((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0');

        $this->deliver($this->issue($email, $purpose, $user, $code), $code);
    }

    /**
     * Count the send against the limits and store the code's hash, in place
     * of the code sent before for the same purpose and user. It holds the
     * locks for the address and for the IP address throughout (always taken
     * in that order), so every limit's count is only ever read and changed
     * by one send at a time.
     *
     * @throws TooManyEmailCodes when a limit is used up, or another send holds a lock too long
     */
    private function issue(string $email, EmailCodePurpose $purpose, ?User $user, #[SensitiveParameter] string $code): EmailCode
    {
        $held = [];

        try {
            foreach ([$this->addressLock($email), $this->ipLock()] as $lock) {
                try {
                    $lock->block(self::WAIT_SECONDS);
                } catch (LockTimeoutException) {
                    throw new TooManyEmailCodes(self::WAIT_SECONDS);
                }

                $held[] = $lock;
            }

            $this->throttle($email);

            return DB::transaction(function () use ($email, $purpose, $user, $code): EmailCode {
                EmailCode::sentTo($email, $purpose, $user)->delete();

                return EmailCode::query()->create([
                    'purpose' => $purpose,
                    'email' => $email,
                    'user_id' => $user?->id,
                    'code_hash' => $this->hasher->make($code),
                    'expires_at' => now()->addMinutes(self::MINUTES_VALID),
                ]);
            });
        } finally {
            foreach (array_reverse($held) as $lock) {
                $lock->release();
            }
        }
    }

    /**
     * Check a code against the latest one sent to the address for the
     * purpose and user, and use it up if it's right.
     *
     * Every try counts, right or wrong, and is counted before the code is
     * checked, in one statement, so tries made at once can't get past the
     * limit. A right code is deleted at once, and only the request that
     * deletes it succeeds, so it works once even when sent twice at once;
     * any other code for the same purpose and user goes with it.
     *
     * @param  User|null  $user  The signed-in user adding the address, or null to sign in with it.
     *
     * @throws EmailCodeRejected when the code is wrong, expired, tried too often or gone
     */
    public function verify(string $email, EmailCodePurpose $purpose, ?User $user, #[SensitiveParameter] string $code): void
    {
        $sent = EmailCode::sentTo(self::address($email), $purpose, $user)->latest('id')->first();

        if (! $sent instanceof EmailCode) {
            throw EmailCodeRejected::missing();
        }

        if ($sent->hasExpired()) {
            throw EmailCodeRejected::expired();
        }

        $counted = EmailCode::query()
            ->whereKey($sent->id)
            ->where('attempts', '<', self::MAX_TRIES)
            ->increment('attempts');

        if ($counted === 0) {
            throw EmailCodeRejected::triedTooOften();
        }

        if (! $this->hasher->check($code, $sent->code_hash)) {
            $attempts = EmailCode::query()->whereKey($sent->id)->value('attempts');

            throw EmailCodeRejected::wrong(self::MAX_TRIES - (is_numeric($attempts) ? (int) $attempts : self::MAX_TRIES));
        }

        if (EmailCode::query()->whereKey($sent->id)->delete() === 0) {
            throw EmailCodeRejected::missing();
        }

        EmailCode::sentTo($sent->email, $purpose, $user)->delete();
    }

    /**
     * How many seconds until another code can be sent to the address from
     * this IP address: 0 when one can be sent now.
     */
    public function secondsUntilNextSend(string $email): int
    {
        $waits = [0];

        foreach ($this->limits(self::address($email)) as $key => [$maxSends]) {
            if (RateLimiter::tooManyAttempts($key, $maxSends)) {
                $waits[] = RateLimiter::availableIn($key);
            }
        }

        return max($waits);
    }

    /**
     * Refuse the send when a limit is used up, or else count it against
     * every limit. Only called while holding the send's locks, so no other
     * send checks or counts the same limits in between.
     *
     * @throws TooManyEmailCodes
     */
    private function throttle(string $email): void
    {
        $wait = $this->secondsUntilNextSend($email);

        if ($wait > 0) {
            throw new TooManyEmailCodes($wait);
        }

        foreach ($this->limits($email) as $key => [, $seconds]) {
            RateLimiter::hit($key, $seconds);
        }
    }

    /**
     * Send the email with the code. Whatever goes wrong while sending has the
     * email, and so the code, in its stack trace, so it never leaves here:
     * the code is deleted, the log names only the kind of failure, and an
     * exception of Nexus's own, with nothing chained to it, goes instead.
     * The email is made in here, so no frame outside holds it.
     *
     * @throws EmailCodeNotSent
     */
    private function deliver(EmailCode $issued, #[SensitiveParameter] string $code): void
    {
        try {
            Notification::route('mail', $issued->email)->notify(new EmailCodeNotification($code, $issued->purpose));
        } catch (Throwable $exception) {
            $issued->delete();

            Log::warning('An email code could not be sent.', ['reason' => $exception::class]);

            throw new EmailCodeNotSent;
        }
    }

    /**
     * The limits on sending, each as the rate limiter's key, the most codes
     * and the window in seconds. The address is hashed into its keys, so the
     * cache holds no addresses.
     *
     * @return array<string, array{int, int}>
     */
    private function limits(string $email): array
    {
        $address = $this->addressKey($email);

        return [
            'email-codes:pause:'.$address => [1, self::SECONDS_BETWEEN_SENDS],
            'email-codes:address:'.$address => [config()->integer('nexus.limits.email_codes_per_address_per_hour'), 3600],
            'email-codes:ip:'.$this->ip() => [config()->integer('nexus.limits.email_codes_per_ip_per_hour'), 3600],
        ];
    }

    /**
     * The lock that sends to one address take turns with.
     */
    private function addressLock(string $email): Lock
    {
        return Cache::lock('email-codes.address.'.$this->addressKey($email), self::LOCK_SECONDS);
    }

    /**
     * The lock that sends from one IP address take turns with.
     */
    private function ipLock(): Lock
    {
        return Cache::lock('email-codes.ip.'.$this->ip(), self::LOCK_SECONDS);
    }

    /**
     * An address as it goes into cache keys: hashed, so the cache holds no
     * addresses.
     */
    private function addressKey(string $email): string
    {
        return hash('sha256', $email);
    }

    private function ip(): string
    {
        return $this->request->ip() ?? 'unknown';
    }
}
