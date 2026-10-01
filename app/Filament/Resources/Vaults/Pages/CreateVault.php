<?php

namespace App\Filament\Resources\Vaults\Pages;

use App\Filament\Resources\Vaults\VaultResource;
use App\Models\Vault;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateVault extends CreateRecord
{
    protected static string $resource = VaultResource::class;

    /**
     * The owner is set through the relationship, not mass assignment, so a
     * crafted form payload can't create a vault for someone else.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $vault = new Vault($data);
        $vault->user()->associate(auth()->user());
        $vault->save();

        return $vault;
    }

    protected function getRedirectUrl(): string
    {
        return VaultResource::getUrl('edit', ['record' => $this->record]);
    }
}
