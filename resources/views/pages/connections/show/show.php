<?php

declare(strict_types=1);

use App\Actions\RefreshCatalog;
use App\Actions\UpdateConnectionServer;
use App\Connectors\Connector;
use App\Enums\ConnectionAuthType;
use App\Models\Connection;
use App\Models\Star;
use App\Rules\HeaderName;
use App\Rules\McpServerUrl;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
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
     * Whether a header value is stored, so a blank field keeps it.
     */
    #[Computed]
    public function hasStoredHeaderValue(): bool
    {
        return $this->connection->auth_type === ConnectionAuthType::Header && $this->connection->headerValue() !== '';
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

        $this->validate(
            [
                'url' => ['required', 'string', 'max:2048', new McpServerUrl],
                'authType' => ['required', Rule::enum(ConnectionAuthType::class)],
                'headerName' => ['exclude_unless:authType,header', 'required', 'string', 'max:64', new HeaderName],
                'headerValue' => ['exclude_unless:authType,header', 'nullable', 'string', 'max:4096', 'not_regex:/[\x00-\x08\x0A-\x1F\x7F]/'],
            ],
            ['headerValue.not_regex' => __('The header value can\'t contain line breaks or other control characters.')],
            [
                'url' => __('server URL'),
                'authType' => __('sign-in'),
                'headerName' => __('header name'),
                'headerValue' => __('header value'),
            ],
        );

        $authType = ConnectionAuthType::from($this->authType);

        $loaded = $updateConnectionServer->handle($this->connection, [
            'url' => $this->url,
            'auth_type' => $authType,
            'header_name' => $authType === ConnectionAuthType::Header ? $this->headerName : null,
        ], $authType === ConnectionAuthType::Header && $this->headerValue !== '' ? $this->headerValue : null);

        unset($this->hasStoredHeaderValue);
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
        $this->toastRefresh($refreshCatalog->handle($this->connection));
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
        $this->resetValidation(['url', 'authType', 'headerName', 'headerValue']);
    }

    /**
     * Say how the catalog refresh that just ran went.
     */
    private function toastRefresh(bool $loaded, ?string $prefix = null): void
    {
        unset($this->toolCount);

        $result = match (true) {
            $loaded => trans_choice('Nexus loaded :count tool.|Nexus loaded :count tools.', $this->toolCount),
            filled($this->connection->last_error) => __('Nexus couldn\'t load the tools: :error', ['error' => $this->connection->last_error]),
            default => __('Nexus couldn\'t load the tools.'),
        };

        Flux::toast(variant: $loaded ? 'success' : 'danger', text: trim($prefix.' '.$result));
    }
};
