<?php

declare(strict_types=1);

use App\Actions\ConnectWithToken;
use App\Actions\SaveNewConnection;
use App\Actions\SuggestConnectionDetails;
use App\Connectors\Connector;
use App\Connectors\ConnectorCatalog;
use App\Enums\ConnectionStatus;
use App\Enums\SignInMethod;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Add connection')] class extends Component
{
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

    #[Computed]
    public function user(): User
    {
        return Auth::user() ?? throw new AuthenticationException;
    }

    /**
     * What to tell the user when they can't add another Connection, or null when they can.
     */
    #[Computed]
    public function limitMessage(): ?string
    {
        return $this->user->hasReachedConnectionLimit() ? SaveNewConnection::limitMessage() : null;
    }

    /**
     * @return array<string, Connector>
     */
    #[Computed]
    public function connectors(): array
    {
        return app(ConnectorCatalog::class)->all();
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
     * Open the connect modal for a connector, with a unique name and handle
     * suggested and its preferred sign-in method chosen.
     */
    public function startConnecting(string $key, SuggestConnectionDetails $suggestConnectionDetails): void
    {
        $connector = app(ConnectorCatalog::class)->find($key);

        abort_unless($connector instanceof Connector && $connector->isAvailable(), 404);

        $this->connectorKey = $connector->key;
        unset($this->connector);

        ['name' => $this->name, 'handle' => $this->handle] = $suggestConnectionDetails->handle($this->user, $connector);
        $this->description = '';
        $this->method = $connector->suggestedMethod()->value ?? '';
        $this->token = '';
        $this->resetValidation();

        $this->dispatch('modal-show', name: 'connect');
    }

    /**
     * Connect the chosen connector. Token sign-in is the only method
     * available so far, so validation lets no other through.
     */
    public function connect(ConnectWithToken $connectWithToken): void
    {
        $connector = $this->connector;

        abort_unless($connector instanceof Connector && $connector->isAvailable(), 404);

        $this->name = trim($this->name);
        $this->handle = trim($this->handle);
        $this->description = trim($this->description);
        $this->token = trim($this->token);

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
            ],
            [
                'handle.regex' => __('Use lowercase letters, digits and dashes, starting with a letter.'),
                'handle.unique' => __('You already have a Connection with this handle.'),
                'method.in' => __('Signing in this way isn\'t available yet.'),
                'token.not_regex' => __('The token can\'t contain line breaks or other control characters.'),
            ],
            [
                'description' => __('use this account for'),
                'method' => __('sign-in'),
            ],
        );

        $connection = $connectWithToken->handle($this->user, $connector, [
            'name' => $this->name,
            'handle' => $this->handle,
            'description' => $this->description === '' ? null : $this->description,
        ], $this->token);

        session()->flash('toast', $connection->status === ConnectionStatus::Connected
            ? ['variant' => 'success', 'text' => trans_choice('Connected. Nexus loaded :count tool.|Connected. Nexus loaded :count tools.', $connection->tools()->count())]
            : ['variant' => 'warning', 'text' => __('Saved, but Nexus couldn\'t load its tools. The Connection page says why.')]);

        $this->redirectRoute('connections.show', ['connection' => $connection], navigate: true);
    }
};
