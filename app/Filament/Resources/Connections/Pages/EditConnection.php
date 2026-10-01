<?php

namespace App\Filament\Resources\Connections\Pages;

use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Filament\Resources\Connections\ConnectionResource;
use App\Models\Connection;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditConnection extends EditRecord
{
    protected static string $resource = ConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ConnectionResource::connectAction(),
            ConnectionResource::refreshToolsAction(),
            DeleteAction::make(),
        ];
    }

    /**
     * Changing the server, the sign-in method or the OAuth app invalidates
     * every token issued so far, so those are cleared and the connection
     * goes back to "not connected".
     *
     * @param  Connection  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $secrets = array_filter(Arr::only($data, CreateConnection::SECRET_FIELDS), filled(...));
        $authType = $data['auth_type'] ?? $record->auth_type;
        $authType = $authType instanceof ConnectionAuthType ? $authType : ConnectionAuthType::from($authType);

        $invalidatesTokens = $record->url !== ($data['url'] ?? $record->url)
            || $record->auth_type !== $authType
            || $record->setting('oauth_client_id') !== data_get($data, 'settings.oauth_client_id')
            || isset($secrets['oauth_client_secret']);

        // Merge settings: the form only holds the visible fields, and keys it
        // doesn't show (like the server's own name for its resource) must survive.
        $settings = [...($record->settings ?? []), ...($data['settings'] ?? [])];

        if ($invalidatesTokens) {
            unset($settings['oauth_resource']);
        }

        $record->fill([...Arr::except($data, CreateConnection::SECRET_FIELDS), 'settings' => $settings]);

        if ($invalidatesTokens) {
            $record->putSecrets([
                'access_token' => null,
                'refresh_token' => null,
                'expires_at' => null,
                'client_id' => null,
                'client_secret' => null,
            ]);

            $record->forceFill([
                'status' => ConnectionStatus::Pending,
                'status_message' => null,
                'protocol_version' => null,
                'account_identity' => null,
            ]);
        }

        $record->putSecrets($secrets)->save();

        return $record;
    }
}
