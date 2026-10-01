<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Models\Connection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class UpdateConnectionServer
{
    public function __construct(private readonly RefreshCatalog $refreshCatalog) {}

    /**
     * Change where a Connection's server is and how Nexus signs in to it,
     * then reload its tools.
     *
     * A new URL clears every stored credential, so none is ever sent to a
     * server it wasn't meant for, and empties the catalog, whose tools
     * belonged to the old server. A header sign-in then needs its value again.
     * Switching to no auth clears the header.
     *
     * @param  array{url: string, auth_type: ConnectionAuthType, header_name: string|null}  $server
     * @param  string|null  $headerValue  A new value for a header sign-in, or null to keep the stored one.
     * @return bool Whether the tools loaded.
     *
     * @throws ValidationException when a header sign-in has no value to send
     */
    public function handle(Connection $connection, array $server, #[SensitiveParameter] ?string $headerValue = null): bool
    {
        $urlChanged = $server['url'] !== $connection->url;

        if ($server['auth_type'] === ConnectionAuthType::Header && $headerValue === null
            && ($urlChanged || $connection->auth_type !== ConnectionAuthType::Header || $connection->headerValue() === '')) {
            throw ValidationException::withMessages([
                'headerValue' => $urlChanged
                    ? __('Enter the header value again. Nexus clears stored credentials when the URL changes.')
                    : __('Enter the header value Nexus should send.'),
            ]);
        }

        if ($urlChanged) {
            $connection->secrets->put(array_fill_keys(array_keys($connection->secrets->all()), null));
        }

        $connection->url = $server['url'];
        $connection->auth_type = $server['auth_type'];

        $settings = Arr::except($connection->settings ?? [], 'header_name');

        if ($server['auth_type'] === ConnectionAuthType::Header) {
            $settings['header_name'] = $server['header_name'] ?? Connection::DEFAULT_HEADER_NAME;

            if ($headerValue !== null) {
                $connection->secrets->put(['header_value' => $headerValue]);
            }
        } else {
            $connection->secrets->put(['header_value' => null]);
        }

        $connection->settings = $settings === [] ? null : $settings;

        DB::transaction(function () use ($connection, $urlChanged): void {
            if ($urlChanged) {
                $connection->tools()->delete();

                $connection->forceFill([
                    'status' => ConnectionStatus::Pending,
                    'last_error' => null,
                    'catalog_refreshed_at' => null,
                ]);
            }

            $connection->save();
        });

        return $this->refreshCatalog->handle($connection);
    }
}
