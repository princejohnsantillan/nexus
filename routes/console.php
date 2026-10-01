<?php

declare(strict_types=1);

use App\Console\Commands\RefreshCatalogsCommand;
use App\Models\ActivityEntry;
use Illuminate\Database\Console\PruneCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(PruneCommand::class, ['--model' => [ActivityEntry::class]])->daily();

Schedule::command(RefreshCatalogsCommand::class)->daily()->onOneServer();
