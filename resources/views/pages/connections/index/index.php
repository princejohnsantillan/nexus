<?php

declare(strict_types=1);

use App\Actions\ConnectWithOAuth;
use App\Actions\ConnectWithToken;
use App\Actions\RefreshCatalog;
use App\Actions\SaveNewConnection;
use App\Actions\SuggestConnectionDetails;
use App\ConnectionOAuth\NexusClient;
use App\Connectors\Connector;
use App\Connectors\ConnectorCatalog;
use App\Enums\ConnectionStatus;
use App\Enums\SignInMethod;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\User;
use App\Stars\ReturnToStar;
use Flux\Flux;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Connections')] class extends Component
{
    /**
     * What the user typed to find a server in the catalog.
     */
    public string $search = '';

    /**
     * The connector the connect modal is for.
     */
    #[Locked]
    public ?string $connectorKey = null;

    public string $name = '';

    public string $handle = '';

    /**
     * What the user uses this account for, to tell it apart from others.
     */
    public string $description = '';

    /**
     * How the user signs in: a SignInMethod value.
     */
    public string $method = '';

    public string $token = '';

    /**
     * The client ID of the user's own OAuth app, for a service whose server
     * only accepts registered apps when this Nexus has none.
     */
    public string $clientId = '';

    public string $clientSecret = '';

    /**
     * The public id of the Star the user is adding a Connection to, from its
     * overview: once it's connected, they go back to it (ReturnToStar).
     */
    #[Locked]
    public ?string $returnTo = null;

    public function mount(): void
    {
        $this->returnTo = ReturnToStar::find($this->user, request()->query(ReturnToStar::QUERY))?->public_id;
    }

    #[Computed]
    public function user(): User
    {
        return Auth::user() ?? throw new AuthenticationException;
    }

    /**
     * The Star the user is adding a Connection to, while it is still theirs.
     */
    #[Computed]
    public function returnStar(): ?Star
    {
        return ReturnToStar::find($this->user, $this->returnTo);
    }

    /**
     * The user's Connections, with their tool counts and the Stars that use them.
     *
     * @return Collection<int, Connection>
     */
    #[Computed]
    public function connections(): Collection
    {
        return $this->user->connections()
            ->withCount('tools')
            ->with(['stars' => fn (Relation $stars): Relation => $stars->orderBy('name')->orderBy('stars.id')])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * How many Connections the user's plan allows, or null when it has no limit.
     */
    #[Computed]
    public function limit(): ?int
    {
        return $this->user->plan()->connectionLimit();
    }

    /**
     * What to tell the user when they can't add another Connection, or null when they can.
     */
    #[Computed]
    public function limitMessage(): ?string
    {
        return $this->limit !== null && $this->connections->count() >= $this->limit ? SaveNewConnection::limitMessage() : null;
    }

    /**
     * How many of the user's Connections were made from each connector, by key.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function connectedCounts(): array
    {
        $counts = [];

        foreach ($this->connections as $connection) {
            if ($connection->connector_key !== null) {
                $counts[$connection->connector_key] = ($counts[$connection->connector_key] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Every connector in the gallery.
     *
     * @return array<string, Connector>
     */
    #[Computed]
    public function connectors(): array
    {
        return app(ConnectorCatalog::class)->all();
    }

    /**
     * The connectors whose name or summary matches the search.
     *
     * @return array<string, Connector>
     */
    #[Computed]
    public function catalog(): array
    {
        $search = trim($this->search);

        if ($search === '') {
            return $this->connectors;
        }

        return array_filter(
            $this->connectors,
            fn (Connector $connector): bool => Str::contains($connector->name.' '.$connector->summary, $search, ignoreCase: true),
        );
    }

    /**
     * The trademark notice for the gallery's names and logos.
     */
    #[Computed]
    public function attribution(): string
    {
        $names = array_map(fn (Connector $connector): string => $connector->name, array_values($this->connectors));

        return __(':names and their logos are trademarks of their respective owners, shown only to identify each service. Nexus is not affiliated with or endorsed by them.', [
            'names' => collect($names)->join(', ', ' '.__('and').' '),
        ]);
    }

    #[Computed]
    public function connector(): ?Connector
    {
        return app(ConnectorCatalog::class)->find($this->connectorKey);
    }

    /**
     * A tool the connect modal's service has, to preview the names agents
     * will see: the first of the user's other accounts of it has, or null
     * when they have none.
     */
    #[Computed]
    public function exampleTool(): ?string
    {
        if ($this->connectorKey === null) {
            return null;
        }

        $name = ConnectionTool::query()
            ->whereIn('connection_id', $this->user->connections()->where('connector_key', $this->connectorKey)->select('id'))
            ->orderBy('connection_id')
            ->orderBy('id')
            ->value('name');

        return is_string($name) ? $name : null;
    }

    /**
     * The callback URL to register on a user's own OAuth app.
     */
    #[Computed]
    public function callbackUrl(): string
    {
        return app(NexusClient::class)->callbackUrl();
    }

    /**
     * Open the connect modal for a connector, with a unique name and handle
     * suggested and its preferred sign-in method chosen.
     */
    public function startConnecting(string $key, SuggestConnectionDetails $suggestConnectionDetails): void
    {
        $connector = app(ConnectorCatalog::class)->find($key);

        abort_unless($connector instanceof Connector && $connector->isAvailable(), 404);

        $this->connectorKey = $connector->key;
        unset($this->connector, $this->exampleTool);

        ['name' => $this->name, 'handle' => $this->handle] = $suggestConnectionDetails->handle($this->user, $connector);
        $this->description = '';
        $this->method = $connector->suggestedMethod()->value ?? '';
        $this->token = '';
        $this->clientId = '';
        $this->clientSecret = '';
        $this->resetValidation();

        $this->dispatch('modal-show', name: 'connect');
    }

    /**
     * Connect the chosen connector: with a token, its tools load straight
     * away; with OAuth, the Connection is saved and the user is sent on to
     * sign in on the service's own page. Adding to a Star, the user goes
     * back to it with the Connection added, once it's connected.
     */
    public function connect(ConnectWithToken $connectWithToken, ConnectWithOAuth $connectWithOAuth, ReturnToStar $returnToStar): void
    {
        $connector = $this->connector;

        abort_unless($connector instanceof Connector && $connector->isAvailable(), 404);

        $this->name = trim($this->name);
        $this->handle = trim($this->handle);
        $this->description = trim($this->description);
        $this->token = trim($this->token);
        $this->clientId = trim($this->clientId);
        $this->clientSecret = trim($this->clientSecret);

        $ownApp = $connector->needsUserApp() ? 'required' : 'prohibited';

        $this->validate(
            [
                'name' => ['required', 'string', 'max:100'],
                'handle' => [
                    'required', 'string', 'max:'.Connection::HANDLE_MAX_LENGTH, 'regex:'.Connection::HANDLE_PATTERN,
                    Rule::unique('connections', 'handle')->where('user_id', $this->user->id),
                ],
                'description' => ['nullable', 'string', 'max:200'],
                'method' => ['required', Rule::in(array_map(fn (SignInMethod $method): string => $method->value, $connector->availableMethods()))],
                'token' => ['exclude_unless:method,'.SignInMethod::Token->value, 'required', 'string', 'max:4000', 'not_regex:/[\x00-\x1F\x7F]/'],
                'clientId' => ['exclude_unless:method,'.SignInMethod::OAuth->value, $ownApp, 'string', 'max:255', 'not_regex:/[\x00-\x20\x7F]/'],
                'clientSecret' => ['exclude_unless:method,'.SignInMethod::OAuth->value, $ownApp, 'string', 'max:4000', 'not_regex:/[\x00-\x1F\x7F]/'],
            ],
            [
                'handle.regex' => __('Use lowercase letters, digits and dashes, starting with a letter.'),
                'handle.unique' => __('You already have a Connection with this handle.'),
                'method.in' => __('Signing in this way isn\'t available.'),
                'token.not_regex' => __('The token can\'t contain line breaks or other control characters.'),
                'clientId.not_regex' => __('The client ID can\'t contain spaces or control characters.'),
                'clientSecret.not_regex' => __('The client secret can\'t contain line breaks or other control characters.'),
            ],
            [
                'description' => __('use this account for'),
                'method' => __('sign-in'),
                'clientId' => __('client ID'),
                'clientSecret' => __('client secret'),
            ],
        );

        $details = [
            'name' => $this->name,
            'handle' => $this->handle,
            'description' => $this->description === '' ? null : $this->description,
        ];

        if ($this->method === SignInMethod::OAuth->value) {
            $connection = $connectWithOAuth->handle($this->user, $connector, $details, $connector->needsUserApp()
                ? ['client_id' => $this->clientId, 'client_secret' => $this->clientSecret]
                : null);

            $this->redirectRoute('connections.connect', ['connection' => $connection, ...ReturnToStar::query($this->returnStar)]);

            return;
        }

        $connection = $connectWithToken->handle($this->user, $connector, $details, $this->token);

        if ($this->returnStar instanceof Star) {
            ['url' => $url, 'toast' => $toast] = $returnToStar->finish($this->returnStar, $connection);

            session()->flash('toast', $toast);
            $this->redirect($url, navigate: true);

            return;
        }

        session()->flash('toast', $connection->status === ConnectionStatus::Connected
            ? ['variant' => 'success', 'text' => trans_choice('Connected. Nexus loaded :count tool.|Connected. Nexus loaded :count tools.', $connection->tools()->count())]
            : ['variant' => 'warning', 'text' => __('Saved, but Nexus couldn\'t load its tools. The Connection page says why.')]);

        $this->redirectRoute('connections.show', ['connection' => $connection], navigate: true);
    }

    /**
     * Re-read one of the user's Connections' tools from its server, and say
     * how it went. Only that account is refreshed, not the user's others of
     * the same service.
     */
    public function refreshTools(int $connectionId, RefreshCatalog $refreshCatalog): void
    {
        $connection = $this->user->connections()->findOrFail($connectionId);

        $loaded = $refreshCatalog->handle($connection);

        unset($this->connections);

        $result = match (true) {
            $loaded => trans_choice(':name: Nexus loaded :count tool.|:name: Nexus loaded :count tools.', $connection->tools()->count(), ['name' => $connection->name]),
            filled($connection->last_error) => __(':name: Nexus couldn\'t load the tools: :error', ['name' => $connection->name, 'error' => $connection->last_error]),
            default => __(':name: Nexus couldn\'t load the tools.', ['name' => $connection->name]),
        };

        $others = $connection->sameServiceConnections();
        $note = $others->isEmpty() ? '' : __('Only this account was refreshed, not :names.', [
            'names' => Arr::join($others->map(fn (Connection $other): string => $other->name)->all(), ', ', __(' and ')),
        ]);

        Flux::toast(variant: $loaded ? 'success' : 'danger', text: trim($result.' '.$note));
    }
};
