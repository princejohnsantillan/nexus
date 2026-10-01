<?php

namespace App\Filament\Resources\ToolCallLogs\Pages;

use App\Filament\Resources\ToolCallLogs\ToolCallLogResource;
use Filament\Resources\Pages\ListRecords;

class ListToolCallLogs extends ListRecords
{
    protected static string $resource = ToolCallLogResource::class;
}
