<?php

namespace App\Filament\Resources\Connections\Pages;

use App\Filament\Resources\Connections\ConnectionResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListConnections extends ListRecords
{
    protected static string $resource = ConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add')
                ->label('Add connection')
                ->icon(Heroicon::OutlinedPlus)
                ->url(ConnectionResource::getUrl('add')),
        ];
    }
}
