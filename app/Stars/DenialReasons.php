<?php

declare(strict_types=1);

namespace App\Stars;

use App\Enums\ActivityKind;
use App\Enums\ActivityStatus;
use App\Enums\DenialReason;
use App\Models\ActivityEntry;
use App\Models\Star;

/**
 * Works out why Nexus refused the calls activity entries record, and which
 * tool or prompt a refused call named that is switched off now, so a call
 * is only said to be refused for a switch, and offered the fix, while that
 * switch is still off.
 *
 * Each Star's tools and prompts are read once (from StarListCache) however
 * many of its entries are asked about, so resolve one instance for a page
 * of entries.
 */
class DenialReasons
{
    /**
     * The tools of each Star asked about so far, by Star id.
     *
     * @var array<int, list<StarTool>>
     */
    private array $tools = [];

    /**
     * The prompts of each Star asked about so far, by Star id.
     *
     * @var array<int, list<StarPrompt>>
     */
    private array $prompts = [];

    public function __construct(
        private readonly StarToolset $toolset,
        private readonly StarPrompts $starPrompts,
    ) {}

    /**
     * Why the entry's call was refused, or null when it wasn't.
     */
    public function reasonFor(ActivityEntry $entry): ?DenialReason
    {
        if ($entry->status !== ActivityStatus::Denied) {
            return null;
        }

        if ($entry->exposed_name === null) {
            return DenialReason::NoName;
        }

        if ($entry->downstream_name === null) {
            return DenialReason::Unknown;
        }

        return $this->switchedOff($entry) !== null ? DenialReason::SwitchedOff : DenialReason::Other;
    }

    /**
     * The tool or prompt the entry's refused call named, when its Star
     * still has it through the same Connection and it is switched off now;
     * otherwise null.
     */
    public function switchedOff(ActivityEntry $entry): StarTool|StarPrompt|null
    {
        $star = $entry->star;

        if ($entry->status !== ActivityStatus::Denied || ! $star instanceof Star || $entry->connection_id === null || $entry->downstream_name === null) {
            return null;
        }

        $named = $entry->kind === ActivityKind::Prompt
            ? array_find($this->promptsOf($star), fn (StarPrompt $prompt): bool => $prompt->connection->id === $entry->connection_id && $prompt->prompt->name === $entry->downstream_name)
            : array_find($this->toolsOf($star), fn (StarTool $tool): bool => $tool->connection->id === $entry->connection_id && $tool->tool->name === $entry->downstream_name);

        return $named !== null && ! $named->enabled ? $named : null;
    }

    /**
     * @return list<StarTool>
     */
    private function toolsOf(Star $star): array
    {
        return $this->tools[$star->id] ??= $this->toolset->tools($star);
    }

    /**
     * @return list<StarPrompt>
     */
    private function promptsOf(Star $star): array
    {
        return $this->prompts[$star->id] ??= $this->starPrompts->prompts($star);
    }
}
