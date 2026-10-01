<?php

namespace App\Filament\Resources\Connections;

use App\Models\ConnectionTool;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;

/**
 * The MCP tool annotations, as table columns. They are the server's own
 * hints about each tool; Nexus shows them and uses read-only for defaults.
 */
class ToolHintColumns
{
    /**
     * @return list<IconColumn>
     */
    public static function make(): array
    {
        return [
            IconColumn::make('read_only')
                ->label('Read-only')
                ->boolean()
                ->tooltip(fn (ConnectionTool $record): string => $record->read_only
                    ? 'Declared read-only: it doesn\'t change anything. On by default in vaults.'
                    : 'Not read-only: it can change things. Off by default in vaults.'),
            IconColumn::make('destructive')
                ->boolean()
                ->trueColor('danger')
                ->falseColor('gray')
                ->tooltip(fn (ConnectionTool $record): string => match (true) {
                    $record->read_only => 'Read-only, so not destructive.',
                    $record->destructive => 'May delete or overwrite data (declared, or assumed because it didn\'t say otherwise).',
                    default => 'Declared not destructive: it only adds or updates.',
                }),
            static::declared('idempotent', 'Idempotent', Heroicon::OutlinedArrowPath, 'success', [
                true => 'Declared idempotent: calling it again with the same arguments has no extra effect.',
                false => 'Declared not idempotent: repeating a call can repeat its effect.',
                null => 'Not declared. The protocol assumes it is not idempotent.',
            ]),
            static::declared('open_world', 'Open world', Heroicon::OutlinedGlobeAlt, 'warning', [
                true => 'Declared open world: it reaches beyond this service, e.g. the web or other people\'s data.',
                false => 'Declared closed world: it only touches this service\'s own data.',
                null => 'Not declared. The protocol assumes it is open world.',
            ]),
        ];
    }

    /**
     * A column for a hint that is shown as declared: yes, no, or not said.
     *
     * @param  array<int|string, string>  $tooltips  Keyed by true, false and null (as 1, 0 and '').
     */
    protected static function declared(string $name, string $label, Heroicon $yesIcon, string $yesColor, array $tooltips): IconColumn
    {
        return IconColumn::make($name)
            ->label($label)
            ->state(fn (ConnectionTool $record): string => match ($record->{$name}) {
                true => 'yes',
                false => 'no',
                null => 'unsaid',
            })
            ->icon(fn (string $state): Heroicon => match ($state) {
                'yes' => $yesIcon,
                'no' => Heroicon::OutlinedXCircle,
                default => Heroicon::OutlinedMinusCircle,
            })
            ->color(fn (string $state): string => $state === 'yes' ? $yesColor : 'gray')
            ->tooltip(fn (ConnectionTool $record): string => $tooltips[match ($record->{$name}) {
                true => 1,
                false => 0,
                null => '',
            }]);
    }
}
