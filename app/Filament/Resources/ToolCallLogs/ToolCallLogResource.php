<?php

namespace App\Filament\Resources\ToolCallLogs;

use App\Enums\ToolCallStatus;
use App\Filament\Resources\ToolCallLogs\Pages\ListToolCallLogs;
use App\Models\ToolCallLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who called what, when, and how it went. Arguments and results are never
 * recorded.
 */
class ToolCallLogResource extends Resource
{
    protected static ?string $model = ToolCallLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Activity';

    protected static ?string $modelLabel = 'tool call';

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', auth()->id());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('vault'))
            ->defaultSort('created_at', 'desc')
            ->description('Metadata only: Nexus never stores arguments, results or prompt contents. Kept for '.config('nexus.logs.retention_days').' days.')
            ->columns([
                TextColumn::make('created_at')->label('When')->since()->dateTimeTooltip()->sortable(),
                TextColumn::make('tool_name')
                    ->label('Tool or prompt')
                    ->fontFamily('mono')
                    ->description(fn (ToolCallLog $record): ?string => $record->kind === 'prompt' ? 'Prompt' : null)
                    ->searchable(),
                TextColumn::make('vault.name')->label('Vault'),
                TextColumn::make('via')->label('Via')->placeholder('Unknown'),
                TextColumn::make('status')->badge(),
                TextColumn::make('duration_ms')->label('Time')->numeric()->suffix(' ms')->sortable(),
                TextColumn::make('response_bytes')->label('Size')->formatStateUsing(fn (int $state): string => number_format($state / 1024, 1).' KB'),
            ])
            ->filters([
                SelectFilter::make('vault_id')->label('Vault')->relationship('vault', 'name', fn (Builder $query): Builder => $query->where('user_id', auth()->id())),
                SelectFilter::make('status')->options(ToolCallStatus::class),
                SelectFilter::make('kind')->options(['tool' => 'Tool calls', 'prompt' => 'Prompts']),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListToolCallLogs::route('/'),
        ];
    }
}
