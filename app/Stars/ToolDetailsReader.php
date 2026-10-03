<?php

declare(strict_types=1);

namespace App\Stars;

use App\Enums\ActivityKind;
use App\Models\ActivityEntry;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\StarToolSwitch;

/**
 * Reads what a tool's details flyout shows, in at most four queries however
 * many Stars and calls there are: which of the user's Stars have the tool
 * on, and its latest calls.
 *
 * Only the user who owns the tool's Connection is looked at: their Stars
 * that include the Connection, and their Activity entries for the tool's
 * exposed name, so the calls of every Star that called it by that name.
 */
class ToolDetailsReader
{
    /**
     * How many of the tool's latest calls the details show.
     */
    public const int RECENT_CALLS = 5;

    /**
     * The tool's details. Give it the tool with its Connection loaded, or
     * reading the Connection is one query more.
     */
    public function read(ConnectionTool $tool): ToolDetails
    {
        $connection = $tool->connection;
        $exposedName = $connection->handle.StarToolset::SEPARATOR.$tool->name;

        $stars = $connection->stars()
            ->where('stars.user_id', $connection->user_id)
            ->orderBy('stars.name')
            ->orderBy('stars.id')
            ->get();

        $switches = [];

        if ($stars->isNotEmpty()) {
            $query = StarToolSwitch::query()
                ->whereIn('star_id', $stars->modelKeys())
                ->where('connection_id', $connection->id)
                ->where('tool_name', $tool->name);

            foreach ($query->get(['star_id', 'enabled']) as $switch) {
                $switches[$switch->star_id] = $switch->enabled;
            }
        }

        $recentCalls = ActivityEntry::query()
            ->where('user_id', $connection->user_id)
            ->where('kind', ActivityKind::Tool)
            ->where('exposed_name', $exposedName)
            ->with('star')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_CALLS)
            ->get();

        return new ToolDetails(
            tool: $tool,
            exposedName: $exposedName,
            stars: array_values($stars->map(fn (Star $star): array => [
                'star' => $star,
                'enabled' => $switches[$star->id] ?? $star->new_tool_policy->enables($tool),
                'followsPolicy' => ! isset($switches[$star->id]),
            ])->all()),
            recentCalls: $recentCalls,
        );
    }
}
