<?php

namespace App\Filament\Resources\Connections\Pages;

use App\Enums\ConnectionAuthType;
use App\Filament\Resources\Connections\ConnectionResource;
use App\Models\Connection;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateConnection extends CreateRecord
{
    protected static string $resource = ConnectionResource::class;

    /**
     * Form fields that are written to the encrypted secrets, not to columns.
     */
    public const SECRET_FIELDS = ['header_value', 'oauth_client_secret'];

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $connection = new Connection(Arr::except($data, self::SECRET_FIELDS));
        $connection->user()->associate(auth()->user());
        $connection->putSecrets(array_filter(Arr::only($data, self::SECRET_FIELDS), filled(...)));
        $connection->save();

        return $connection;
    }

    protected function afterCreate(): void
    {
        /** @var Connection $connection */
        $connection = $this->record;

        if ($connection->auth_type !== ConnectionAuthType::OAuth) {
            ConnectionResource::refreshTools($connection);
        }
    }

    protected function getRedirectUrl(): string
    {
        /** @var Connection $connection */
        $connection = $this->record;

        return $connection->auth_type === ConnectionAuthType::OAuth
            ? route('connections.oauth.connect', $connection)
            : ConnectionResource::getUrl('edit', ['record' => $connection]);
    }
}
