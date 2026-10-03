<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\EmailCodePurpose;
use App\Notifications\EmailCodeNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Assert;

/**
 * Reads the one-time codes Nexus emailed, from what Notification::fake()
 * caught, the way a user reads them from their inbox.
 */
final class SentEmailCodes
{
    /**
     * The code in the latest email sent to the address for the purpose.
     */
    public static function latest(string $email, EmailCodePurpose $purpose = EmailCodePurpose::SignIn): string
    {
        $sent = self::all($email, $purpose)[0] ?? null;

        Assert::assertInstanceOf(EmailCodeNotification::class, $sent, 'No code was emailed to the address.');

        return $sent->code;
    }

    /**
     * Every email with a code sent to the address for the purpose, newest first.
     *
     * @return list<EmailCodeNotification>
     */
    public static function all(string $email, EmailCodePurpose $purpose = EmailCodePurpose::SignIn): array
    {
        return Notification::sent(
            new AnonymousNotifiable,
            EmailCodeNotification::class,
            fn (EmailCodeNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routeNotificationFor('mail') === $email && $notification->purpose === $purpose,
        )->reverse()->values()->all();
    }

    /**
     * The email as its recipient gets it.
     */
    public static function mail(EmailCodeNotification $email): MailMessage
    {
        return $email->toMail(new AnonymousNotifiable);
    }

    /**
     * A code that isn't the one sent.
     */
    public static function wrong(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }
}
