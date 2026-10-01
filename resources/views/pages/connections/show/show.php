<?php

declare(strict_types=1);

use App\Actions\RefreshCatalog;
use App\Actions\UpdateConnectionServer;
use App\ConnectionOAuth\NexusClient;
use App\Connectors\Connector;
use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\Star;
use App\Rules\HeaderName;
use App\Rules\McpServerUrl;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Connection')] class extends Component
{
    public Connection $connection;

    public string $name = '';

    /**
     * What the user uses this account for, to tell it apart from others.
     */
    public string $description = '';

    public string $url = '';

    public string $authType = 'none';

    public string $headerName = Connection::DEFAULT_HEADER_NAME;

    /**
     * A new header value. Blank keeps the stored one, which never leaves the server.
     */
    public string $headerValue = '';

    /**
     * A new token, for a Connection that signs in to its connector with one.
     */
    public string $token = '';

    /**
     * The client ID of an OAuth app the user registered on a custom server.
     */
    public string $clientId = '';

    /**
     * A new secret for that app. Blank keeps the stored one.
     */
    public string $clientSecret = '';

    public function mount(): void
    {
        $this->name = $this->connection->name;
        $this->description = $this->connection->description ?? '';
        $this->resetServerForm();
    }

    /**
     * The gallery connector the Connection was made from, or null for a custom server.
     */
    #[Computed]
    public function connector(): ?Connector
    {
        return $this->connection->connector();
    }

    #[Computed]
    public function toolCount(): int
    {
        return $this->connection->tools()->count();
    }

    /**
     * The Stars that include this Connection, which lose its tools when it is deleted.
     *
     * @return Collection<int, Star>
     */
    #[Computed]
    public function stars(): Collection
    {
        return $this->connection->stars()->orderBy('name')->orderBy('stars.id')->get();
    }

    /**
     * The user's other Connections of the same service: the other accounts,
     * whose tools refreshing this one leaves alone.
     *
     * @return Collection<int, Connection>
     */
    #[Computed]
    public function sameServiceConnections(): Collection
    {
        return $this->connection->sameServiceConnections();
    }

    /**
     * The names of the user's other Connections of the same service, as one
     * phrase: "GitHub 2 and GitHub 3".
     */
    #[Computed]
    public function sameServiceNames(): string
    {
        $names = array_map(fn (Connection $connection): string => $connection->name, $this->sameServiceConnections->all());

        return Arr::join($names, ', ', __(' and '));
    }

    /**
     * Whether a header value is stored, so a blank field keeps it.
     */
    #[Computed]
    public function hasStoredHeaderValue(): bool
    {
        return $this->connection->auth_type === ConnectionAuthType::Header && $this->connection->headerValue() !== '';
    }

    /**
     * Whether a secret for the user's own OAuth app is stored, so a blank field keeps it.
     */
    #[Computed]
    public function hasStoredClientSecret(): bool
    {
        return $this->connection->oauthClientId() !== null && filled($this->connection->secrets->get('oauth_client_secret'));
    }

    /**
     * Whether the user has to sign in again before Nexus can use the server.
     */
    #[Computed]
    public function needsOAuthSignIn(): bool
    {
        return $this->connection->usesOAuth() && $this->connection->status === ConnectionStatus::NeedsAuth;
    }

    /**
     * The callback URL to register on an OAuth app of the user's own.
     */
    #[Computed]
    public function callbackUrl(): string
    {
        return app(NexusClient::class)->callbackUrl();
    }

    public function saveDetails(): void
    {
        $this->name = trim($this->name);
        $this->description = trim($this->description);

        $this->validate(
            [
                'name' => ['required', 'string', 'max:100'],
                'description' => ['nullable', 'string', 'max:200'],
            ],
            attributes: ['description' => __('use this account for')],
        );

        $this->connection->update([
            'name' => $this->name,
            'description' => $this->description === '' ? null : $this->description,
        ]);

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    /**
     * Change a custom server's URL or sign-in. A connector's server is fixed.
     */
    public function saveServer(UpdateConnectionServer $updateConnectionServer): void
    {
        abort_if($this->connector instanceof Connector, 404);

        $this->url = trim($this->url);
        $this->headerName = trim($this->headerName);
        $this->clientId = trim($this->clientId);
        $this->clientSecret = trim($this->clientSecret);

        $this->validate(
            [
                'url' => ['required', 'string', 'max:2048', new McpServerUrl],
                'authType' => ['required', Rule::enum(ConnectionAuthType::class)],
                'headerName' => ['exclude_unless:authType,header', 'required', 'string', 'max:64', new HeaderName],
                'headerValue' => ['exclude_unless:authType,header', 'nullable', 'string', 'max:4096', 'not_regex:/[\x00-\x08\x0A-\x1F\x7F]/'],
                'clientId' => ['exclude_unless:authType,oauth', 'nullable', 'string', 'max:255', 'not_regex:/[\x00-\x20\x7F]/'],
                'clientSecret' => ['exclude_unless:authType,oauth', 'nullable', 'string', 'max:4000', 'not_regex:/[\x00-\x1F\x7F]/'],
            ],
            [
                'headerValue.not_regex' => __('The header value can\'t contain line breaks or other control characters.'),
                'clientId.not_regex' => __('The client ID can\'t contain spaces or control characters.'),
                'clientSecret.not_regex' => __('The client secret can\'t contain line breaks or other control characters.'),
            ],
            [
                'url' => __('server URL'),
                'authType' => __('sign-in'),
                'headerName' => __('header name'),
                'headerValue' => __('header value'),
                'clientId' => __('client ID'),
                'clientSecret' => __('client secret'),
            ],
        );

        $authType = ConnectionAuthType::from($this->authType);
        $usesOAuth = $authType === ConnectionAuthType::OAuth;

        $loaded = $updateConnectionServer->handle(
            $this->connection,
            [
                'url' => $this->url,
                'auth_type' => $authType,
                'header_name' => $authType === ConnectionAuthType::Header ? $this->headerName : null,
                'oauth_client_id' => $usesOAuth && $this->clientId !== '' ? $this->clientId : null,
            ],
            $authType === ConnectionAuthType::Header && $this->headerValue !== '' ? $this->headerValue : null,
            $usesOAuth && $this->clientSecret !== '' ? $this->clientSecret : null,
        );

        if ($usesOAuth && ! $this->connection->hasAccessToken()) {
            $this->redirectRoute('connections.connect', ['connection' => $this->connection]);

            return;
        }

        unset($this->hasStoredHeaderValue, $this->hasStoredClientSecret, $this->needsOAuthSignIn);
        $this->resetServerForm();
        $this->toastRefresh($loaded, __('Saved.'));
    }

    /**
     * Replace the token a Connection signs in to its connector with, then
     * reload its tools.
     */
    public function replaceToken(UpdateConnectionServer $updateConnectionServer): void
    {
        $tokenSignIn = $this->connector?->token;

        abort_unless($tokenSignIn !== null && $this->connection->usesConnectorToken(), 404);

        $this->token = trim($this->token);

        $this->validate(
            ['token' => ['required', 'string', 'max:4000', 'not_regex:/[\x00-\x1F\x7F]/']],
            ['token.not_regex' => __('The token can\'t contain line breaks or other control characters.')],
        );

        $loaded = $updateConnectionServer->handle($this->connection, [
            'url' => $this->connection->url,
            'auth_type' => ConnectionAuthType::Header,
            'header_name' => $tokenSignIn->headerName,
        ], $tokenSignIn->headerValue($this->token));

        $this->token = '';
        $this->toastRefresh($loaded, __('Token replaced.'));
    }

    public function refreshTools(RefreshCatalog $refreshCatalog): void
    {
        $loaded = $refreshCatalog->handle($this->connection);

        unset($this->needsOAuthSignIn);
        $this->toastRefresh($loaded, onlyThisAccount: true);
    }

    public function delete(): void
    {
        $this->connection->delete();

        session()->flash('toast', ['variant' => 'success', 'text' => __('Deleted :name.', ['name' => $this->connection->name])]);

        $this->redirectRoute('connections.index', navigate: true);
    }

    /**
     * Fill the server form from the Connection, leaving the header value blank.
     */
    private function resetServerForm(): void
    {
        $this->url = $this->connection->url;
        $this->authType = $this->connection->auth_type->value;
        $this->headerName = $this->connection->headerName();
        $this->headerValue = '';
        $this->clientId = $this->connection->oauthClientId() ?? '';
        $this->clientSecret = '';
        $this->resetValidation(['url', 'authType', 'headerName', 'headerValue', 'clientId', 'clientSecret']);
    }

    /**
     * Say how the catalog refresh that just ran went, after what led to it.
     * With `onlyThisAccount`, also say it left the user's other accounts of
     * the same service alone, if they have any.
     */
    private function toastRefresh(bool $loaded, ?string $prefix = null, bool $onlyThisAccount = false): void
    {
        unset($this->toolCount);

        $result = match (true) {
            $loaded => trans_choice('Nexus loaded :count tool.|Nexus loaded :count tools.', $this->toolCount),
            filled($this->connection->last_error) => __('Nexus couldn\'t load the tools: :error', ['error' => $this->connection->last_error]),
            default => __('Nexus couldn\'t load the tools.'),
        };

        $note = $onlyThisAccount && $this->sameServiceConnections->isNotEmpty()
            ? __('Only this account was refreshed, not :names.', ['names' => $this->sameServiceNames])
            : '';

        Flux::toast(variant: $loaded ? 'success' : 'danger', text: trim($prefix.' '.$result.' '.$note));
    }
};
