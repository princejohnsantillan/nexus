<?php

namespace App\Filament\Resources\Vaults\RelationManagers;

use App\Models\Vault;
use App\Models\VaultToken;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * @method Vault getOwnerRecord()
 */
class TokensRelationManager extends RelationManager
{
    protected static string $relationship = 'tokens';

    protected static ?string $title = 'Tokens';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->description('Give each client or machine its own token, so you can revoke one without breaking the others.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->latest())
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('hint')->label('Token')->fontFamily('mono'),
                TextColumn::make('state')
                    ->badge()
                    ->state(fn (VaultToken $record): string => match (true) {
                        $record->revoked_at !== null => 'Revoked',
                        ! $record->isUsable() => 'Expired',
                        default => 'Active',
                    })
                    ->color(fn (string $state): string => $state === 'Active' ? 'success' : 'gray'),
                TextColumn::make('last_used_at')->label('Last used')->since()->placeholder('Never'),
                TextColumn::make('expires_at')->label('Expires')->date()->placeholder('Never'),
                TextColumn::make('created_at')->label('Created')->since(),
            ])
            ->headerActions([
                Action::make('issue')
                    ->label('New token')
                    ->icon(Heroicon::OutlinedKey)
                    ->disabled(fn (): bool => $this->activeTokenCount() >= config('nexus.limits.tokens_per_vault'))
                    ->tooltip(fn (): ?string => $this->activeTokenCount() >= config('nexus.limits.tokens_per_vault')
                        ? 'This vault has the maximum number of active tokens. Revoke one first.'
                        : null)
                    ->schema([
                        TextInput::make('name')
                            ->label('Used by')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('Claude Code on my MacBook'),
                        Select::make('expires_in_days')
                            ->label('Expires')
                            ->options([
                                '' => 'Never',
                                '30' => 'In 30 days',
                                '90' => 'In 90 days',
                                '365' => 'In a year',
                            ])
                            ->default('90')
                            ->selectablePlaceholder(false),
                    ])
                    ->action(function (array $data): void {
                        abort_if($this->activeTokenCount() >= config('nexus.limits.tokens_per_vault'), 422);

                        [, $plain] = VaultToken::issue(
                            $this->getOwnerRecord(),
                            $data['name'],
                            filled($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null,
                        );

                        $this->replaceMountedAction('showToken', ['token' => $plain]);
                    }),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Clients using this token lose access immediately.')
                    ->visible(fn (VaultToken $record): bool => $record->revoked_at === null)
                    ->action(fn (VaultToken $record) => $record->revoke()),
            ]);
    }

    public function showTokenAction(): Action
    {
        return Action::make('showToken')
            ->modalHeading('Copy your new token')
            ->modalDescription('Nexus stores only a hash of it, so this is the only time it is shown.')
            ->modalContent(fn (array $arguments) => view('filament.vaults.client-setup', [
                'vault' => $this->getOwnerRecord(),
                'token' => $arguments['token'] ?? null,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Done')
            ->modalWidth('3xl');
    }

    protected function activeTokenCount(): int
    {
        return $this->getOwnerRecord()->tokens()->whereNull('revoked_at')->count();
    }
}
