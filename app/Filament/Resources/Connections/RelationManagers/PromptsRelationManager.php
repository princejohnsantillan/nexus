<?php

namespace App\Filament\Resources\Connections\RelationManagers;

use App\Models\ConnectionPrompt;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The prompts this connection's server advertised at the last refresh.
 * Read-only: switch prompts on and off per vault.
 */
class PromptsRelationManager extends RelationManager
{
    protected static string $relationship = 'prompts';

    protected static ?string $title = 'Prompts';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->description('Templates you invoke from a client, e.g. as a slash command in Claude Code. They\'re on in every vault unless you switch them off there.')
            ->emptyStateHeading('No prompts')
            ->emptyStateDescription('This server doesn\'t offer any prompts.')
            ->columns([
                TextColumn::make('name')
                    ->fontFamily('mono')
                    ->description(fn (ConnectionPrompt $record): ?string => $record->title)
                    ->searchable(),
                TextColumn::make('description')->limit(120)->wrap(),
                TextColumn::make('arguments')
                    ->state(fn (ConnectionPrompt $record): string => collect($record->arguments())
                        ->map(fn (array $argument): string => $argument['name'].(($argument['required'] ?? false) ? '*' : ''))
                        ->implode(', '))
                    ->placeholder('None')
                    ->tooltip('* means required'),
            ]);
    }
}
