<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\BillingCalendar;
use App\Billing\ProReminders;
use App\Enums\ProReminder;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nexus:billing:remind {--dry-run : List who would be emailed, without sending anything}')]
#[Description('Email users whose Pro ends within 7 days or ended in the last 48 hours, once per end date (the scheduler runs this hourly)')]
class SendProRemindersCommand extends Command
{
    /**
     * Queue each Pro email to everyone due it, or with --dry-run list them
     * and send nothing.
     */
    public function handle(ProReminders $reminders): int
    {
        foreach (ProReminder::cases() as $reminder) {
            if ($this->option('dry-run')) {
                $this->listDue($reminders, $reminder);

                continue;
            }

            $sent = $reminders->send($reminder);

            $this->components->info(trans_choice('Queued ":email" for :count user.|Queued ":email" for :count users.', $sent, ['email' => $reminder->label()]));
        }

        return self::SUCCESS;
    }

    private function listDue(ProReminders $reminders, ProReminder $reminder): void
    {
        $due = $reminders->due($reminder)
            ->map(fn (User $user): array => [
                $user->id,
                $user->email,
                $user->pro_until === null ? '' : BillingCalendar::local($user->pro_until)->format('M j, Y g:i A'),
            ])
            ->all();

        $this->components->info(trans_choice('Would email ":email" to :count user (dry run, nothing sent).|Would email ":email" to :count users (dry run, nothing sent).', count($due), ['email' => $reminder->label()]));

        if ($due !== []) {
            $this->table(['User', 'Email', __('Pro until (:timezone)', ['timezone' => BillingCalendar::timezone()])], $due);
        }
    }
}
