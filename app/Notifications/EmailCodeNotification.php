<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Auth\EmailCodes;
use App\Enums\EmailCodePurpose;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * The email carrying a one-time code, to sign in or to add the address to
 * an account, sent to the address on demand through the configured mailer.
 * Like Laravel's own password reset, it is sent at once, never queued, so
 * the code never sits in a job's payload; and it stays out of the subject.
 */
class EmailCodeNotification extends Notification
{
    public function __construct(
        #[SensitiveParameter] public readonly string $code,
        public readonly EmailCodePurpose $purpose,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(match ($this->purpose) {
                EmailCodePurpose::SignIn => __('Your Nexus sign-in code'),
                EmailCodePurpose::AddToAccount => __('Confirm your email address for Nexus'),
            })
            ->markdown('mail.email-code', [
                'code' => $this->code,
                'purpose' => $this->purpose,
                'minutes' => EmailCodes::MINUTES_VALID,
            ]);
    }
}
