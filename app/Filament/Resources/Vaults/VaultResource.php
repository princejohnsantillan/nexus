<?php

namespace App\Filament\Resources\Vaults;

use App\Enums\VaultAuthMode;
use App\Filament\Resources\Vaults\Pages\CreateVault;
use App\Filament\Resources\Vaults\Pages\EditVault;
use App\Filament\Resources\Vaults\Pages\ListVaults;
use App\Filament\Resources\Vaults\Pages\ManageVaultTools;
use App\Filament\Resources\Vaults\RelationManagers\ConnectedAppsRelationManager;
use App\Filament\Resources\Vaults\RelationManagers\TokensRelationManager;
use App\Models\Connection;
use App\Models\Vault;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VaultResource extends Resource
{
    protected static ?string $model = Vault::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', auth()->id());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('Work'),
                        Textarea::make('description')
                            ->maxLength(1000)
                            ->rows(2)
                            ->helperText('Optional. Sent to the agent as the server\'s instructions.'),
                        Select::make('connections')
                            ->relationship(
                                'connections',
                                'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->where('connections.user_id', auth()->id()),
                            )
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->rule(fn (): Closure => static::ownConnectionsRule())
                            ->helperText('Read-only tools are switched on automatically. Turn on write tools under Tools.'),
                    ]),
                Section::make('How clients sign in')
                    ->columnSpanFull()
                    ->schema([
                        Radio::make('auth_mode')
                            ->hiddenLabel()
                            ->options(VaultAuthMode::class)
                            ->default(VaultAuthMode::Token)
                            ->required()
                            ->helperText(fn (string $operation): ?string => $operation === 'edit'
                                ? 'Changing this stops the current credentials from working: tokens, the signed URL or approved apps, depending on the old mode.'
                                : null),
                    ]),
                Section::make('Endpoint')
                    ->columnSpanFull()
                    ->visibleOn('edit')
                    ->schema([
                        TextEntry::make('endpoint')
                            ->label(fn (?Vault $record): string => $record?->auth_mode === VaultAuthMode::SignedUrl ? 'Signed URL' : 'MCP URL')
                            ->state(fn (?Vault $record): ?string => $record?->clientUrl())
                            ->fontFamily('mono')
                            ->copyable()
                            ->helperText(fn (?Vault $record): ?string => match ($record?->auth_mode) {
                                VaultAuthMode::Token => 'Clients connect here with one of this vault\'s tokens. Create a token below to get setup instructions.',
                                VaultAuthMode::SignedUrl => 'Anyone with this URL can use the vault. Treat it like a password; "Rotate URL" revokes it.',
                                VaultAuthMode::OAuth => 'Clients connecting here are sent to Nexus, where you sign in and approve them. They appear under Connected apps.',
                                null => null,
                            }),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('connections'))
            ->columns([
                TextColumn::make('name')
                    ->description(fn (Vault $record): ?string => $record->description)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('connections_count')->label('Connections')->numeric(),
                TextColumn::make('auth_mode')->label('Sign-in')->badge()->color('gray'),
                TextColumn::make('public_id')
                    ->label('MCP URL')
                    ->formatStateUsing(fn (Vault $record): string => $record->endpointUrl())
                    ->fontFamily('mono')
                    ->copyable()
                    ->copyableState(fn (Vault $record): string => $record->endpointUrl()),
            ])
            ->recordActions([
                static::manageToolsAction(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            TokensRelationManager::class,
            ConnectedAppsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVaults::route('/'),
            'create' => CreateVault::route('/create'),
            'edit' => EditVault::route('/{record}/edit'),
            'tools' => ManageVaultTools::route('/{record}/tools'),
        ];
    }

    public static function manageToolsAction(): Action
    {
        return Action::make('tools')
            ->label('Tools')
            ->icon(Heroicon::OutlinedWrenchScrewdriver)
            ->color('gray')
            ->url(fn (Vault $record): string => static::getUrl('tools', ['record' => $record]));
    }

    /**
     * Connections must belong to the vault's owner, even if a crafted
     * request submits someone else's ids.
     *
     * @return Closure(string, mixed, Closure): void
     */
    protected static function ownConnectionsRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $ids = array_filter((array) $value);

            if ($ids !== [] && Connection::query()->whereKey($ids)->where('user_id', '!=', auth()->id())->exists()) {
                $fail('Choose from your own connections.');
            }
        };
    }
}
