<?php

declare(strict_types=1);

namespace App\Stars;

use App\Models\ActivityEntry;
use App\Models\ConnectionTool;
use App\Models\Star;
use Illuminate\Database\Eloquent\Collection;

/**
 * Everything about one tool of a Connection, for its details flyout: the
 * tool as its server listed it, the name clients call it by, whether each
 * of the user's Stars that include its Connection has it on, and its
 * latest calls in Activity.
 */
final readonly class ToolDetails
{
    /**
     * @param  ConnectionTool  $tool  The catalog entry, with its Connection.
     * @param  string  $exposedName  The name clients call it by, `{handle}__{tool}`.
     * @param  list<array{star: Star, enabled: bool, followsPolicy: bool}>  $stars  The user's Stars that include its Connection, by name: whether each has it on, and whether that is the Star's new-tool policy rather than the user's own switch.
     * @param  Collection<int, ActivityEntry>  $recentCalls  Its latest calls, newest first, each with its Star.
     */
    public function __construct(
        public ConnectionTool $tool,
        public string $exposedName,
        public array $stars,
        public Collection $recentCalls,
    ) {}
}
