<?php

namespace App\Filament\Resources\Vaults\Pages;

use App\Filament\Resources\Vaults\VaultResource;
use App\Models\Vault;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditVault extends EditRecord
{
    protected static string $resource = VaultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            VaultResource::manageToolsAction(),
            Action::make('setup')
                ->label('Setup instructions')
                ->icon(Heroicon::OutlinedCommandLine)
                ->color('gray')
                ->modalHeading('Connect a client to this vault')
                ->modalContent(fn (Vault $record) => view('filament.vaults.client-setup', ['vault' => $record, 'token' => null]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),
            DeleteAction::make(),
        ];
    }
}
