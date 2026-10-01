<?php

namespace App\Filament\Resources\Vaults\RelationManagers;

use App\Enums\VaultAuthMode;
use App\Models\Vault;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

/**
 * The OAuth apps (claude.ai, Claude Code, Cursor…) that registered for an
 * OAuth-mode vault, and whether they currently hold access.
 *
 * @method Vault getOwnerRecord()
 */
class ConnectedAppsRelationManager extends RelationManager
{
    protected static string $relationship = 'oauthClients';

    protected static ?string $title = 'Connected apps';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Vault && $ownerRecord->auth_mode === VaultAuthMode::OAuth;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->description('Apps that registered to use this vault. Each one only got access once you approved it.')
            ->emptyStateHeading('No apps yet')
            ->emptyStateDescription('Add this vault\'s URL to an MCP client; it will appear here after you approve it.')
            ->columns([
                TextColumn::make('name')->label('App'),
                TextColumn::make('redirect_uris')
                    ->label('Returns to')
                    ->state(fn (Client $record): string => collect($record->redirect_uris)->map(fn (string $uri): string => parse_url($uri, PHP_URL_HOST) ?: $uri)->unique()->implode(', ')),
                TextColumn::make('access')
                    ->badge()
                    ->state(fn (Client $record): string => match (true) {
                        $record->revoked => 'Revoked',
                        $this->activeTokens($record)->exists() => 'Has access',
                        default => 'Not approved',
                    })
                    ->color(fn (string $state): string => $state === 'Has access' ? 'success' : 'gray'),
                TextColumn::make('last_approved')
                    ->label('Last approved')
                    ->state(fn (Client $record): mixed => $record->tokens()->where('user_id', $this->getOwnerRecord()->user_id)->max('created_at'))
                    ->since()
                    ->placeholder('Never'),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The app loses access immediately. To use this vault again it has to be added and approved again.')
                    ->visible(fn (Client $record): bool => ! $record->revoked)
                    ->action(fn (Client $record) => $this->revoke($record)),
            ]);
    }

    /**
     * Revoke the app outright: its access and refresh tokens, and the client
     * itself, so it can't quietly ask for new tokens.
     */
    protected function revoke(Client $client): void
    {
        $tokenIds = $client->tokens()->pluck('id');

        Passport::token()->newQuery()->whereKey($tokenIds)->update(['revoked' => true]);
        Passport::refreshToken()->newQuery()->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);

        $client->forceFill(['revoked' => true])->save();
    }

    /**
     * @return HasMany<Token, Client>
     */
    protected function activeTokens(Client $client): HasMany
    {
        return $client->tokens()
            ->where('user_id', $this->getOwnerRecord()->user_id)
            ->where('revoked', false)
            ->where('expires_at', '>', now());
    }
}
