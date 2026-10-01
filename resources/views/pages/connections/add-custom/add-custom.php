<?php

declare(strict_types=1);

use App\Actions\AddCustomConnection;
use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\User;
use App\Rules\HeaderName;
use App\Rules\McpServerUrl;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Custom MCP server')] class extends Component
{
    public string $name = '';

    public string $handle = '';

    /**
     * What the user uses this account for, to tell it apart from others.
     */
    public string $description = '';

    public string $url = '';

    public string $authType = 'none';

    public string $headerName = Connection::DEFAULT_HEADER_NAME;

    public string $headerValue = '';

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
        return $this->user->hasReachedConnectionLimit() ? AddCustomConnection::limitMessage() : null;
    }

    public function save(AddCustomConnection $addCustomConnection): void
    {
        $this->name = trim($this->name);
        $this->handle = trim($this->handle);
        $this->description = trim($this->description);
        $this->url = trim($this->url);
        $this->headerName = trim($this->headerName);

        $this->validate(
            [
                'name' => ['required', 'string', 'max:100'],
                'handle' => [
                    'required', 'string', 'max:'.Connection::HANDLE_MAX_LENGTH, 'regex:'.Connection::HANDLE_PATTERN,
                    Rule::unique('connections', 'handle')->where('user_id', $this->user->id),
                ],
                'description' => ['nullable', 'string', 'max:200'],
                'url' => ['required', 'string', 'max:2048', new McpServerUrl],
                'authType' => ['required', Rule::enum(ConnectionAuthType::class)],
                'headerName' => ['exclude_unless:authType,header', 'required', 'string', 'max:64', new HeaderName],
                'headerValue' => ['exclude_unless:authType,header', 'required', 'string', 'max:4096', 'not_regex:/[\x00-\x08\x0A-\x1F\x7F]/'],
            ],
            [
                'handle.regex' => __('Use lowercase letters, digits and dashes, starting with a letter.'),
                'handle.unique' => __('You already have a Connection with this handle.'),
                'headerValue.not_regex' => __('The header value can\'t contain line breaks or other control characters.'),
            ],
            [
                'description' => __('use this account for'),
                'url' => __('server URL'),
                'authType' => __('sign-in'),
                'headerName' => __('header name'),
                'headerValue' => __('header value'),
            ],
        );

        $authType = ConnectionAuthType::from($this->authType);

        $connection = $addCustomConnection->handle($this->user, [
            'name' => $this->name,
            'handle' => $this->handle,
            'description' => $this->description === '' ? null : $this->description,
            'url' => $this->url,
            'auth_type' => $authType,
            'header_name' => $authType === ConnectionAuthType::Header ? $this->headerName : null,
        ], $authType === ConnectionAuthType::Header ? $this->headerValue : null);

        session()->flash('toast', $connection->status === ConnectionStatus::Connected
            ? ['variant' => 'success', 'text' => trans_choice('Connected. Nexus loaded :count tool.|Connected. Nexus loaded :count tools.', $connection->tools()->count())]
            : ['variant' => 'warning', 'text' => __('Saved, but Nexus couldn\'t load its tools. The Connection page says why.')]);

        $this->redirectRoute('connections.show', ['connection' => $connection], navigate: true);
    }
};
