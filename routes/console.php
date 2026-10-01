<?php

use App\Models\ToolCallLog;
use Illuminate\Support\Facades\Schedule;

Schedule::command('nexus:refresh-catalogs')->daily()->withoutOverlapping();

Schedule::command('model:prune', ['--model' => [ToolCallLog::class]])->daily();
