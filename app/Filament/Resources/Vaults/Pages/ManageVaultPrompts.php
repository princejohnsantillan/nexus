<?php

namespace App\Filament\Resources\Vaults\Pages;

use App\Filament\Resources\Vaults\VaultResource;
use App\Mcp\Vaults\ExposedPrompt;
use App\Mcp\Vaults\VaultPrompts;
use App\Models\ConnectionPrompt;
use App\Models\Vault;
use App\Models\VaultPrompt;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Switch individual prompts on or off for one vault.
 *
 * @property Vault $record
 */
class ManageVaultPrompts extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = VaultResource::class;

    protected string $view = 'filament.resources.vaults.pages.manage-vault-prompts';

    /** @var Collection<string, VaultPrompt>|null */
    protected ?Collection $switches = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(VaultResource::canEdit($this->record), 403);
    }

    public function getTitle(): string
    {
        return "Prompts in {$this->record->name}";
    }

    public function getSubheading(): string
    {
        return 'Prompts are templates you invoke from a client, like a slash command. They only produce instructions, so they\'re on by default; any tool they lead to still needs its own switch.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ConnectionPrompt::query()
                ->whereIn('connection_id', $this->record->connections()->pluck('connections.id'))
                ->with('connection'))
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('connection_id')->orderBy('name'))
            ->columns([
                TextColumn::make('name')
                    ->label('Prompt')
                    ->state(fn (ConnectionPrompt $record): string => ExposedPrompt::nameFor($record->connection, $record))
                    ->description(fn (ConnectionPrompt $record): ?string => $record->title ?? $record->description)
                    ->fontFamily('mono')
                    ->searchable(['name', 'title', 'description']),
                TextColumn::make('connection.name')->label('Connection'),
                TextColumn::make('arguments')
                    ->state(fn (ConnectionPrompt $record): string => collect($record->arguments())
                        ->map(fn (array $argument): string => $argument['name'].(($argument['required'] ?? false) ? '*' : ''))
                        ->implode(', '))
                    ->placeholder('None'),
                ToggleColumn::make('enabled')
                    ->label('On')
                    ->state(fn (ConnectionPrompt $record): bool => $this->isEnabled($record))
                    ->updateStateUsing(function (ConnectionPrompt $record, bool $state): bool {
                        app(VaultPrompts::class)->setEnabled($this->record, $record, $state);
                        $this->switches = null;

                        return $state;
                    }),
            ])
            ->filters([
                SelectFilter::make('connection_id')
                    ->label('Connection')
                    ->options(fn (): array => $this->record->connections()->pluck('name', 'connections.id')->all()),
            ])
            ->emptyStateHeading('No prompts')
            ->emptyStateDescription('None of this vault\'s connections offer prompts.');
    }

    protected function isEnabled(ConnectionPrompt $prompt): bool
    {
        $this->switches ??= $this->record->promptOverrides()->get()
            ->keyBy(fn (VaultPrompt $switch): string => $switch->connection_id.'|'.$switch->prompt_name);

        return $this->switches->get($prompt->connection_id.'|'.$prompt->name)?->enabled ?? true;
    }
}
