<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ReconcilePayments;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nexus:billing:reconcile')]
#[Description('Confirm pending PayMongo payments nobody came back for, and expire stale checkouts (the scheduler runs this every 10 minutes)')]
class ReconcilePaymentsCommand extends Command
{
    /**
     * Settle the pending payments ReconcilePayments looks at, and say how they stand.
     */
    public function handle(ReconcilePayments $reconcilePayments): int
    {
        ['paid' => $paid, 'expired' => $expired, 'pending' => $pending, 'deferred' => $deferred] = $reconcilePayments->handle();

        if ($paid + $expired + $pending + $deferred === 0) {
            $this->components->info(__('No pending payments to reconcile.'));

            return self::SUCCESS;
        }

        $this->components->info(trans_choice('Looked at :count pending payment: :paid paid, :expired expired, :pending still pending.|Looked at :count pending payments: :paid paid, :expired expired, :pending still pending.', $paid + $expired + $pending, [
            'paid' => $paid,
            'expired' => $expired,
            'pending' => $pending,
        ]));

        if ($deferred > 0) {
            $this->components->info(trans_choice(':count more is left for the next run.|:count more are left for the next run.', $deferred));
        }

        return self::SUCCESS;
    }
}
