<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Billing\BillingCalendar;
use App\Enums\Plan;
use App\Enums\ProReminder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;

/**
 * The email that the user's Pro ends soon, or has ended, for the
 * `pro_until` it was written for (App\Billing\ProReminders sends it). It is
 * queued, unlike a sign-in code: nothing in it is secret. It isn't sent if
 * Pro was extended, or the email stopped being due, while it waited in the
 * queue, and its job is dropped if the user was deleted.
 */
#[DeleteWhenMissingModels]
class ProReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ProReminder $reminder,
        public readonly CarbonImmutable $proUntil,
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
     * Whether the email still holds when it goes out: the user's Pro still
     * ends when it says, and the email is still due. A "Pro ends soon" that
     * waited until Pro ended, or a "Pro has ended" that waited past its 48
     * hours, isn't sent.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $notifiable instanceof User
            && $notifiable->pro_until?->equalTo($this->proUntil) === true
            && $this->reminder->isDue($this->proUntil, CarbonImmutable::now());
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $date = BillingCalendar::date($this->proUntil);

        return (new MailMessage)
            ->subject(match ($this->reminder) {
                ProReminder::EndsSoon => __('Your Nexus Pro ends on :date', ['date' => $date]),
                ProReminder::Ended => __('Your Nexus Pro has ended'),
            })
            ->markdown('mail.pro-reminder', [
                'reminder' => $this->reminder,
                'date' => $date,
                'stars' => Plan::Free->starLimit(),
                'connections' => Plan::Free->connectionLimit(),
                'toolCalls' => Plan::Free->toolCallsPerWeek(),
                'extendUrl' => route('billing.upgrade'),
            ]);
    }
}
