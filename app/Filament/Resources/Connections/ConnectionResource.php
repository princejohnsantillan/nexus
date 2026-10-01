<?php

namespace App\Filament\Resources\Connections;

use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Filament\Resources\Connections\Pages\AddConnection;
use App\Filament\Resources\Connections\Pages\CreateConnection;
use App\Filament\Resources\Connections\Pages\EditConnection;
use App\Filament\Resources\Connections\Pages\ListConnections;
use App\Filament\Resources\Connections\RelationManagers\ToolsRelationManager;
use App\Mcp\Downstream\AccountIdentity;
use App\Mcp\Downstream\ConnectionCatalog;
use App\Mcp\Downstream\ConnectionNeedsAuth;
use App\Models\Connection;
use App\Security\OutboundGuard;
use App\Security\OutboundRequestBlocked;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use Throwable;

class ConnectionResource extends Resource
{
    protected static ?string $model = Connection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', auth()->id());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Server')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('Slack (BetterWorld)'),
                        TextInput::make('handle')
                            ->required()
                            ->maxLength(24)
                            ->regex(Connection::HANDLE_PATTERN)
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('user_id', auth()->id()))
                            ->disabledOn('edit')
                            ->placeholder('slack')
                            ->helperText('Prefix for this connection\'s tools, e.g. slack__search_messages. Lowercase letters, digits and dashes. Can\'t be changed later, because client permission rules match on tool names.'),
                        Textarea::make('description')
                            ->label('Use this account for')
                            ->rows(2)
                            ->maxLength(500)
                            ->placeholder('BetterWorld work workspace. Anything about BetterWorld, bw-api, deploys or teammates.')
                            ->helperText('Optional. When a vault has more than one account of the same service, agents read this to pick the right one.')
                            ->columnSpanFull(),
                        TextEntry::make('connector')
                            ->label('Connector')
                            ->state(fn (?Connection $record): ?string => $record?->connectorDefinition() === null ? null : "{$record->connectorDefinition()->name} (official MCP server)")
                            ->url(fn (?Connection $record): ?string => $record?->connectorDefinition()?->docsUrl, shouldOpenInNewTab: true)
                            ->visible(fn (?Connection $record): bool => $record?->connectorDefinition() !== null)
                            ->columnSpanFull(),
                        TextInput::make('url')
                            ->label('MCP server URL')
                            ->disabled(fn (?Connection $record): bool => $record?->connectorDefinition() !== null)
                            ->required()
                            ->url()
                            ->maxLength(2048)
                            ->rule(fn (): Closure => static::publicUrlRule())
                            ->placeholder('https://mcp.linear.app/mcp')
                            ->helperText('The server\'s remote (Streamable HTTP) endpoint. Local stdio servers can\'t be connected.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Authentication')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('auth_type')
                            ->label('How Nexus signs in')
                            ->options(ConnectionAuthType::class)
                            ->default(ConnectionAuthType::OAuth)
                            ->disabled(fn (?Connection $record): bool => $record?->connectorDefinition() !== null)
                            ->required()
                            ->live(),
                        TextInput::make('settings.header_name')
                            ->label('Header name')
                            ->default('Authorization')
                            ->disabled(fn (?Connection $record): bool => $record?->connectorDefinition()?->supportsToken() === true)
                            ->required()
                            ->maxLength(100)
                            ->visible(static::whenAuthIs(ConnectionAuthType::Header)),
                        TextInput::make('header_value')
                            ->label('Header value')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->placeholder('Bearer sk-…')
                            ->helperText(fn (string $operation, ?Connection $record): string => match (true) {
                                $operation === 'create' => 'Stored encrypted. Include the scheme if the server expects one, e.g. "Bearer …".',
                                $record?->connectorDefinition()?->supportsToken() === true => 'Stored encrypted. Paste a new token to replace the current one, or leave blank to keep it.',
                                default => 'Stored encrypted. Leave blank to keep the current value.',
                            })
                            ->visible(static::whenAuthIs(ConnectionAuthType::Header)),
                        Section::make('Your own OAuth app (optional)')
                            ->description('Most servers let Nexus register itself. Slack and GitHub don\'t: create an OAuth app there, enter its credentials here, and set its callback URL to the one below.')
                            ->collapsible()
                            ->collapsed(fn (?Connection $record): bool => blank($record?->setting('oauth_client_id')))
                            ->columns(2)
                            ->visible(static::whenAuthIs(ConnectionAuthType::OAuth))
                            ->schema([
                                TextInput::make('settings.oauth_client_id')
                                    ->label('Client ID')
                                    ->maxLength(255),
                                TextInput::make('oauth_client_secret')
                                    ->label('Client secret')
                                    ->password()
                                    ->revealable()
                                    ->dehydrated(fn (?string $state): bool => filled($state))
                                    ->helperText('Stored encrypted. Leave blank to keep the current value.'),
                                TextInput::make('settings.oauth_scope')
                                    ->label('Scopes')
                                    ->maxLength(1000)
                                    ->helperText('Space-separated. Leave blank to use what the server asks for.'),
                                TextEntry::make('callback_url')
                                    ->label('Callback URL')
                                    ->state(fn (): string => route('oauth.callback'))
                                    ->copyable(),
                            ]),
                    ]),
                Section::make('Status')
                    ->columns(4)
                    ->columnSpanFull()
                    ->visibleOn('edit')
                    ->schema([
                        TextEntry::make('status')->badge(),
                        TextEntry::make('account_identity')->label('Signed in as')->placeholder('Not reported by the server'),
                        TextEntry::make('tools_refreshed_at')->label('Tools last refreshed')->since()->placeholder('Never'),
                        TextEntry::make('protocol_version')->label('Protocol')->placeholder('Unknown'),
                        TextEntry::make('status_message')->label('Last problem')->columnSpanFull()->visible(fn (?Connection $record): bool => filled($record?->status_message)),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('tools'))
            ->columns([
                TextColumn::make('name')
                    ->description(fn (Connection $record): string => collect([$record->handle, $record->account_identity])->filter()->implode(' · '))
                    ->searchable(['name', 'handle'])
                    ->sortable(),
                TextColumn::make('connector')
                    ->label('Type')
                    ->state(fn (Connection $record): string => $record->connectorDefinition()->name ?? 'Custom')
                    ->icon(fn (Connection $record): Heroicon|string => $record->connectorDefinition()->icon ?? Heroicon::OutlinedServerStack)
                    ->badge()
                    ->color('gray'),
                TextColumn::make('url')
                    ->label('Server')
                    ->formatStateUsing(fn (string $state): string => (string) parse_url($state, PHP_URL_HOST))
                    ->tooltip(fn (Connection $record): string => $record->url),
                TextColumn::make('auth_type')->label('Sign-in')->badge()->color('gray'),
                TextColumn::make('status')->badge(),
                TextColumn::make('tools_count')->label('Tools')->numeric(),
                TextColumn::make('tools_refreshed_at')->label('Refreshed')->since()->placeholder('Never')->sortable(),
            ])
            ->recordActions([
                static::connectAction(),
                static::refreshToolsAction(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ToolsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConnections::route('/'),
            'add' => AddConnection::route('/add'),
            'create' => CreateConnection::route('/create'),
            'edit' => EditConnection::route('/{record}/edit'),
        ];
    }

    public static function connectAction(): Action
    {
        return Action::make('connect')
            ->label(fn (Connection $record): string => $record->status === ConnectionStatus::Pending ? 'Sign in' : 'Reconnect')
            ->icon(Heroicon::OutlinedArrowRightEndOnRectangle)
            ->color(fn (Connection $record): string => $record->isUsable() ? 'gray' : 'primary')
            ->url(fn (Connection $record): string => route('connections.oauth.connect', $record))
            ->visible(fn (Connection $record): bool => $record->auth_type === ConnectionAuthType::OAuth);
    }

    public static function refreshToolsAction(): Action
    {
        return Action::make('refreshTools')
            ->label('Refresh tools')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (Connection $record): bool => $record->auth_type !== ConnectionAuthType::OAuth || filled($record->secret('access_token')))
            ->action(fn (Connection $record) => static::refreshTools($record));
    }

    public static function refreshTools(Connection $connection): void
    {
        try {
            $count = app(ConnectionCatalog::class)->refresh($connection);

            Notification::make()->success()->title("{$connection->name}: {$count} tools available")->send();

            if (($twin = AccountIdentity::sameAccountAs($connection->refresh())) !== null) {
                Notification::make()
                    ->warning()
                    ->persistent()
                    ->title("Same account as {$twin->name}")
                    ->body("Both connections are signed in as {$connection->account_identity}. If you meant a different account, sign out of it at the provider (or use a private window) and reconnect.")
                    ->send();
            }
        } catch (ConnectionNeedsAuth $exception) {
            Notification::make()->warning()->title('Sign-in needed')->body($exception->getMessage())->send();
        } catch (Throwable $exception) {
            Notification::make()->danger()->title("Couldn't list {$connection->name}'s tools")->body($exception->getMessage())->send();
        }
    }

    /**
     * @return Closure(Get): bool
     */
    protected static function whenAuthIs(ConnectionAuthType $type): Closure
    {
        return function (Get $get) use ($type): bool {
            $state = $get('auth_type');

            return ($state instanceof ConnectionAuthType ? $state : ConnectionAuthType::tryFrom((string) $state)) === $type;
        };
    }

    /**
     * @return Closure(string, mixed, Closure): void
     */
    protected static function publicUrlRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            try {
                app(OutboundGuard::class)->check((string) $value);
            } catch (OutboundRequestBlocked $exception) {
                $fail($exception->getMessage());
            }
        };
    }
}
