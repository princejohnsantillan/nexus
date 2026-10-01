<?php

namespace App\Filament\Resources\Vaults\Pages;

use App\Filament\Resources\Vaults\VaultResource;
use App\Mcp\Vaults\ExposedTool;
use App\Mcp\Vaults\VaultToolset;
use App\Models\ConnectionTool;
use App\Models\Vault;
use App\Models\VaultTool;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Switch individual tools on or off for one vault.
 *
 * @property Vault $record
 */
class ManageVaultTools extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = VaultResource::class;

    protected string $view = 'filament.resources.vaults.pages.manage-vault-tools';

    /** @var Collection<string, VaultTool>|null */
    protected ?Collection $switches = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(VaultResource::canEdit($this->record), 403);
    }

    public function getTitle(): string
    {
        return "Tools in {$this->record->name}";
    }

    public function getSubheading(): string
    {
        return 'Read-only tools are on by default and everything else starts off. New tools from a server follow the same rule. Each switch you flip here overrides the default.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ConnectionTool::query()
                ->whereIn('connection_id', $this->record->connections()->pluck('connections.id'))
                ->with('connection'))
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('connection_id')->orderBy('name'))
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('name')
                    ->label('Tool')
                    ->state(fn (ConnectionTool $record): string => ExposedTool::nameFor($record->connection, $record))
                    ->description(fn (ConnectionTool $record): ?string => $record->title)
                    ->fontFamily('mono')
                    ->searchable(['name', 'title', 'description']),
                TextColumn::make('connection.name')->label('Connection'),
                IconColumn::make('read_only')->label('Read-only')->boolean(),
                IconColumn::make('destructive')->boolean()->trueColor('danger')->falseColor('gray'),
                ToggleColumn::make('enabled')
                    ->label('On')
                    ->state(fn (ConnectionTool $record): bool => $this->isEnabled($record))
                    ->updateStateUsing(function (ConnectionTool $record, bool $state): bool {
                        app(VaultToolset::class)->setEnabled($this->record, $record, $state);
                        $this->switches = null;

                        return $state;
                    }),
            ])
            ->filters([
                SelectFilter::make('connection_id')
                    ->label('Connection')
                    ->options(fn (): array => $this->record->connections()->pluck('name', 'connections.id')->all()),
                TernaryFilter::make('read_only')->label('Read-only'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    $this->bulkSwitch('enable', 'Turn on', Heroicon::OutlinedCheckCircle, fn (ConnectionTool $tool) => app(VaultToolset::class)->setEnabled($this->record, $tool, true)),
                    $this->bulkSwitch('disable', 'Turn off', Heroicon::OutlinedXCircle, fn (ConnectionTool $tool) => app(VaultToolset::class)->setEnabled($this->record, $tool, false)),
                    $this->bulkSwitch('reset', 'Reset to default', Heroicon::OutlinedArrowUturnLeft, fn (ConnectionTool $tool) => app(VaultToolset::class)->resetToDefault($this->record, $tool)),
                ]),
            ])
            ->emptyStateHeading('No tools yet')
            ->emptyStateDescription('Add connections to this vault, and make sure each one is signed in and its tools have been refreshed.');
    }

    protected function bulkSwitch(string $name, string $label, Heroicon $icon, callable $apply): BulkAction
    {
        return BulkAction::make($name)
            ->label($label)
            ->icon($icon)
            ->action(function (Collection $records) use ($apply): void {
                $records->each($apply);
                $this->switches = null;
            })
            ->deselectRecordsAfterCompletion();
    }

    protected function isEnabled(ConnectionTool $tool): bool
    {
        $this->switches ??= $this->record->toolOverrides()->get()
            ->keyBy(fn (VaultTool $switch): string => $switch->connection_id.'|'.$switch->tool_name);

        return $this->switches->get($tool->connection_id.'|'.$tool->name)?->enabled
            ?? VaultToolset::enabledByDefault($tool);
    }
}
