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
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Request;
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
 * with a pause between codes, and per IP address; the limits are counted
 * atomically, so of two sends to one address at once only one gets through.
 * What happens is the same whether or not anyone signs in with the address,
 * so sending reveals nothing about accounts.
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

        $this->throttle($email);

        $code = Str::padLeft((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0');

        $issued = DB::transaction(function () use ($email, $purpose, $user, $code): EmailCode {
            EmailCode::sentTo($email, $purpose, $user)->delete();

            return EmailCode::query()->create([
                'purpose' => $purpose,
                'email' => $email,
                'user_id' => $user?->id,
                'code_hash' => $this->hasher->make($code),
                'expires_at' => now()->addMinutes(self::MINUTES_VALID),
            ]);
        });

        $this->deliver($issued, $code);
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
     * Count a send against every limit, refusing it when one is used up. Each
     * count is an atomic increment, checked afterwards, so sends at the same
     * moment can't all slip under a limit; a refused send takes its counts
     * back, so it costs nothing.
     *
     * @throws TooManyEmailCodes
     */
    private function throttle(string $email): void
    {
        $counted = [];

        foreach ($this->limits($email) as $key => [$maxSends, $seconds]) {
            $counted[$key] = $seconds;

            if (RateLimiter::increment($key, $seconds) > $maxSends) {
                foreach ($counted as $countedKey => $countedSeconds) {
                    RateLimiter::decrement($countedKey, $countedSeconds);
                }

                throw new TooManyEmailCodes(max(1, $this->secondsUntilNextSend($email)));
            }
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
        $address = hash('sha256', $email);

        return [
            'email-codes:pause:'.$address => [1, self::SECONDS_BETWEEN_SENDS],
            'email-codes:address:'.$address => [config()->integer('nexus.limits.email_codes_per_address_per_hour'), 3600],
            'email-codes:ip:'.($this->request->ip() ?? 'unknown') => [config()->integer('nexus.limits.email_codes_per_ip_per_hour'), 3600],
        ];
    }
}
