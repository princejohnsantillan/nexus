<?php

declare(strict_types=1);

use App\Console\Commands\ReconcilePaymentsCommand;
use App\Console\Commands\RefreshCatalogsCommand;
use App\Console\Commands\SendProRemindersCommand;
use App\Models\ActivityEntry;
use App\Models\EmailCode;
use App\Models\ToolCallCount;
use Illuminate\Database\Console\PruneCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Laravel\Passport\Console\PurgeCommand;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(PruneCommand::class, ['--model' => [ActivityEntry::class, EmailCode::class, ToolCallCount::class]])->daily();

Schedule::command(RefreshCatalogsCommand::class)->daily()->onOneServer();

Schedule::command(PurgeCommand::class)->daily();

Schedule::command(SendProRemindersCommand::class)->hourly()->onOneServer();

// The lock lasts 10 minutes, so a run that dies holding it never blocks the next one for long.
Schedule::command(ReconcilePaymentsCommand::class)->everyTenMinutes()->onOneServer()->withoutOverlapping(10);
