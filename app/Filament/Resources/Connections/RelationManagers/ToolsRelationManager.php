<?php

namespace App\Filament\Resources\Connections\RelationManagers;

use App\Models\ConnectionTool;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * The tools this connection's server advertised at the last refresh.
 * Read-only: switch tools on and off per vault.
 */
class ToolsRelationManager extends RelationManager
{
    protected static string $relationship = 'tools';

    protected static ?string $title = 'Tools';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->description('Tools are switched on per vault. Read-only tools are on by default; everything else starts off.')
            ->columns([
                TextColumn::make('name')
                    ->fontFamily('mono')
                    ->description(fn (ConnectionTool $record): ?string => $record->title)
                    ->searchable(),
                TextColumn::make('description')->limit(120)->wrap()->toggleable(),
                IconColumn::make('read_only')->label('Read-only')->boolean(),
                IconColumn::make('destructive')->boolean()->trueColor('danger')->falseColor('gray'),
            ])
            ->filters([
                TernaryFilter::make('read_only')->label('Read-only'),
            ]);
    }
}
