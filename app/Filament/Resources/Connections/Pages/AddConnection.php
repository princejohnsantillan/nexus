<?php

namespace App\Filament\Resources\Connections\Pages;

use App\Connectors\Connector;
use App\Connectors\ConnectorCatalog;
use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Filament\Resources\Connections\ConnectionResource;
use App\Models\Connection;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rules\Unique;

/**
 * Pick a connector, name it, sign in. A "custom" card leads to the full form
 * for any other MCP server.
 */
class AddConnection extends Page
{
    protected static string $resource = ConnectionResource::class;

    protected static ?string $title = 'Add a connection';

    public function getSubheading(): string
    {
        return 'Pick a service and sign in. Each one uses the service\'s official MCP server.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])->schema([
                ...collect(ConnectorCatalog::all())->map(fn (Connector $connector): Section => $this->card($connector))->values()->all(),
                $this->customCard(),
            ]),
        ]);
    }

    protected function card(Connector $connector): Section
    {
        return Section::make($connector->name.($connector->preview ? ' (preview)' : ''))
            ->key("connector-{$connector->key}")
            ->description($connector->isAvailable()
                ? $connector->summary
                : "{$connector->summary} Not set up on this Nexus yet: {$connector->appInstructions}")
            ->icon($connector->icon)
            ->footerActions([$this->connectAction($connector)]);
    }

    protected function customCard(): Section
    {
        return Section::make('Custom MCP server')
            ->key('connector-custom')
            ->description('Any other remote MCP server: enter its URL and how Nexus should sign in.')
            ->icon(Heroicon::OutlinedServerStack)
            ->footerActions([
                Action::make('custom')
                    ->label('Set up')
                    ->color('gray')
                    ->url(ConnectionResource::getUrl('create'))
                    ->disabled(! ConnectionResource::canCreate()),
            ]);
    }

    protected function connectAction(Connector $connector): Action
    {
        return Action::make("connect_{$connector->key}")
            ->label('Connect')
            ->disabled(fn (): bool => ! $connector->isAvailable() || ! ConnectionResource::canCreate())
            ->tooltip(fn (): ?string => match (true) {
                ! $connector->isAvailable() => "This Nexus hasn't configured a {$connector->name} app yet.",
                ! ConnectionResource::canCreate() => 'You have reached the maximum number of connections.',
                default => null,
            })
            ->modalHeading("Connect {$connector->name}")
            ->modalDescription('Connect the same service again to add another account.')
            ->modalSubmitActionLabel('Connect')
            ->fillForm(fn (): array => [
                'name' => $this->suggestedName($connector),
                'handle' => $this->suggestedHandle($connector),
                'method' => $connector->suggestedMethod(),
            ])
            ->schema(fn (): array => [
                TextInput::make('name')
                    ->required()
                    ->maxLength(100),
                TextInput::make('handle')
                    ->required()
                    ->maxLength(24)
                    ->regex(Connection::HANDLE_PATTERN)
                    ->unique(table: 'connections', column: 'handle', modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('user_id', auth()->id()))
                    ->helperText("Prefix for its tools, e.g. {$connector->key}__… Lowercase letters, digits and dashes; can't be changed later."),
                Textarea::make('description')
                    ->label('Use this account for')
                    ->rows(2)
                    ->maxLength(500)
                    ->placeholder('Optional, e.g. "BetterWorld work account". Helps agents pick between two accounts.'),
                ...$this->methodChoice($connector),
                ...$this->tokenFields($connector),
                ...$this->userClientFields($connector),
            ])
            ->action(function (array $data) use ($connector): void {
                abort_unless($connector->isAvailable() && ConnectionResource::canCreate(), 403);

                $withToken = $this->method($connector, $data['method'] ?? null) === 'token';

                $connection = new Connection([
                    'connector' => $connector->key,
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'handle' => $data['handle'],
                    'url' => $connector->url,
                    'auth_type' => $withToken ? ConnectionAuthType::Header : ConnectionAuthType::OAuth,
                    'status' => ConnectionStatus::Pending,
                    'settings' => $withToken
                        ? ['header_name' => $connector->token->header]
                        : array_filter(['oauth_client_id' => $data['oauth_client_id'] ?? null]),
                ]);
                $connection->user()->associate(auth()->user());
                $connection->putSecrets($withToken
                    ? ['header_value' => $connector->token->headerValue((string) $data['token'])]
                    : ['oauth_client_secret' => $data['oauth_client_secret'] ?? null]);
                $connection->save();

                if ($withToken) {
                    ConnectionResource::refreshTools($connection);
                    $this->redirect(ConnectionResource::getUrl('edit', ['record' => $connection]));

                    return;
                }

                $this->redirect(route('connections.oauth.connect', $connection));
            });
    }

    /**
     * Offered only when both methods can work for this connector here.
     *
     * @return list<Radio>
     */
    protected function methodChoice(Connector $connector): array
    {
        if (! $connector->supportsToken() || ! $connector->supportsOAuth()) {
            return [];
        }

        return [
            Radio::make('method')
                ->label('How to connect')
                ->options([
                    'token' => 'With your own token',
                    'oauth' => "Sign in with {$connector->name}",
                ])
                ->descriptions([
                    'token' => 'Nexus acts as you, with exactly the access you give the token. No app needed.',
                    'oauth' => $connector->needsUserClient()
                        ? 'Signs in through an OAuth app, which you register yourself.'
                        : 'Signs in through this Nexus\'s app.',
                ])
                ->required()
                ->live(),
        ];
    }

    /**
     * @return list<Section>
     */
    protected function tokenFields(Connector $connector): array
    {
        if (! $connector->supportsToken()) {
            return [];
        }

        return [
            Section::make('Your token')
                ->description($connector->token->instructions)
                ->visible(fn (Get $get): bool => $this->method($connector, $get('method')) === 'token')
                ->schema([
                    TextEntry::make('token_console')
                        ->label('Create one at')
                        ->state($connector->token->consoleUrl)
                        ->url($connector->token->consoleUrl, shouldOpenInNewTab: true),
                    TextInput::make('token')
                        ->label('Token')
                        ->password()
                        ->revealable()
                        ->required()
                        ->helperText('Stored encrypted. Nexus checks it by loading the tools right away.'),
                ]),
        ];
    }

    /**
     * For servers that only accept registered apps, when this deployment
     * hasn't configured one: ask for the user's own.
     *
     * @return list<Section>
     */
    protected function userClientFields(Connector $connector): array
    {
        if (! $connector->needsUserClient()) {
            return [];
        }

        return [
            Section::make("Your {$connector->name} app")
                ->description($connector->appInstructions)
                ->visible(fn (Get $get): bool => $this->method($connector, $get('method')) === 'oauth')
                ->schema([
                    TextEntry::make('callback_url')
                        ->label('Callback URL')
                        ->state(route('oauth.callback'))
                        ->copyable(),
                    TextEntry::make('manifest')
                        ->label('App manifest')
                        ->state($connector->appManifestJson(route('oauth.callback')))
                        ->fontFamily('mono')
                        ->size('xs')
                        ->copyable()
                        ->visible($connector->appManifest !== null),
                    TextEntry::make('console')
                        ->label('Create the app at')
                        ->state($connector->appConsoleUrl)
                        ->url($connector->appConsoleUrl, shouldOpenInNewTab: true),
                    TextInput::make('oauth_client_id')
                        ->label('Client ID')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('oauth_client_secret')
                        ->label('Client secret')
                        ->password()
                        ->revealable()
                        ->required()
                        ->helperText('Stored encrypted.'),
                ]),
        ];
    }

    /**
     * The chosen sign-in method, falling back to the only one available.
     */
    protected function method(Connector $connector, ?string $chosen): string
    {
        return match (true) {
            ! $connector->supportsToken() => 'oauth',
            ! $connector->supportsOAuth() => 'token',
            default => $chosen ?? $connector->suggestedMethod(),
        };
    }

    protected function suggestedName(Connector $connector): string
    {
        $count = Connection::query()->where('user_id', auth()->id())->where('connector', $connector->key)->count();

        return $count === 0 ? $connector->name : "{$connector->name} ".($count + 1);
    }

    protected function suggestedHandle(Connector $connector): string
    {
        $taken = Connection::query()->where('user_id', auth()->id())->pluck('handle')->flip();

        $handle = $connector->key;

        for ($suffix = 2; $taken->has($handle); $suffix++) {
            $handle = "{$connector->key}-{$suffix}";
        }

        return $handle;
    }
}
