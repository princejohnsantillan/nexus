<?php

declare(strict_types=1);

use App\Actions\SwitchStarTools;
use App\Enums\NewToolPolicy;
use App\Enums\ToolRisk;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Stars\StarTool;
use App\Stars\StarToolset;
use App\Stars\ToolDetails;
use App\Stars\ToolDetailsReader;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Star tools')] class extends Component
{
    public Star $star;

    /**
     * Shows only the tools whose exposed name or title contains it.
     */
    public string $search = '';

    public string $policy = '';

    /**
     * The catalog id of the tool whose details flyout is open, or null.
     */
    public ?int $detailsToolId = null;

    public function mount(): void
    {
        $this->policy = $this->star->new_tool_policy->value;
    }

    /**
     * Every tool of the Star's Connections, on or off.
     *
     * @return list<StarTool>
     */
    #[Computed]
    public function starTools(): array
    {
        return resolve(StarToolset::class)->tools($this->star);
    }

    /**
     * Each of the Star's Connections, by name, with the tools the filter
     * shows, sorted into risk groups, and how many of all its tools are on.
     * A risk group counts only the tools the filter shows, as its switch
     * changes only those, and is left out when it shows none.
     *
     * @return list<array{connection: Connection, tools: list<StarTool>, risks: list<array{risk: ToolRisk, tools: list<StarTool>, enabled: int, ownChoices: int}>, enabled: int, total: int}>
     */
    #[Computed]
    public function groups(): array
    {
        $tools = [];

        foreach ($this->starTools as $tool) {
            $tools[$tool->connection->id][] = $tool;
        }

        $connections = $this->star->connections()->orderBy('name')->orderBy('connections.id')->get();

        return array_values($connections->map(function (Connection $connection) use ($tools): array {
            $shown = array_values(array_filter($tools[$connection->id] ?? [], $this->isShown(...)));

            return [
                'connection' => $connection,
                'tools' => $shown,
                'risks' => $this->riskGroups($shown),
                'enabled' => count(array_filter($tools[$connection->id] ?? [], fn (StarTool $tool): bool => $tool->enabled)),
                'total' => count($tools[$connection->id] ?? []),
            ];
        })->all());
    }

    /**
     * The tool whose details flyout is open, or null when none is or the
     * Star no longer has it.
     */
    #[Computed]
    public function detailsTool(): ?StarTool
    {
        return $this->detailsToolId === null ? null : $this->starTool($this->detailsToolId);
    }

    /**
     * What the details flyout shows for its tool.
     */
    #[Computed]
    public function toolDetails(): ?ToolDetails
    {
        $tool = $this->detailsTool;

        return $tool === null ? null : resolve(ToolDetailsReader::class)->read($tool->tool->setRelation('connection', $tool->connection));
    }

    /**
     * Open the details flyout for one of the Star's tools.
     */
    public function showToolDetails(int $toolId): void
    {
        $this->starTool($toolId) ?? abort(404);

        $this->detailsToolId = $toolId;

        unset($this->detailsTool, $this->toolDetails);

        $this->dispatch('modal-show', name: 'tool-details', scope: $this->getId());
    }

    public function updatedPolicy(): void
    {
        $this->validate(['policy' => ['required', Rule::enum(NewToolPolicy::class)]]);

        $policy = NewToolPolicy::from($this->policy);

        $this->star->update(['new_tool_policy' => $policy]);

        $this->forgetTools();

        Flux::toast(variant: 'success', text: __('New-tool policy: :policy.', ['policy' => $policy->label()]));
    }

    /**
     * Switch one tool on or off as the user's own choice.
     */
    public function switchTool(int $toolId, bool $enabled, SwitchStarTools $switchStarTools): void
    {
        $tool = $this->findTool($toolId);

        $switchStarTools->handle($this->star, $tool->connection, $enabled, [$tool->name]);

        $this->forgetTools();
    }

    /**
     * Let one tool follow the new-tool policy again.
     */
    public function resetTool(int $toolId, SwitchStarTools $switchStarTools): void
    {
        $tool = $this->findTool($toolId);

        $switchStarTools->handle($this->star, $tool->connection, null, [$tool->name]);

        $this->forgetTools();
    }

    /**
     * Switch every tool the filter shows for one Connection on (`on`) or off
     * (`off`), or let them follow the policy again (`reset`). Without a
     * filter that is all of its tools.
     */
    public function switchConnection(int $connectionId, string $choice, SwitchStarTools $switchStarTools): void
    {
        $enabled = $this->choice($choice);

        $connection = $this->star->connections()->findOrFail($connectionId);

        $group = collect($this->groups)->firstWhere('connection.id', $connection->id);
        $filtered = trim($this->search) !== '';

        $toolNames = $filtered ? $this->toolNames($group['tools'] ?? []) : null;
        $count = $toolNames === null ? $group['total'] ?? 0 : count($toolNames);

        $switchStarTools->handle($this->star, $connection, $enabled, $toolNames);

        $this->forgetTools();

        $replace = ['connection' => $connection->name];

        Flux::toast(variant: 'success', text: match ($enabled) {
            true => trans_choice('Switched on :count :connection tool.|Switched on :count :connection tools.', $count, $replace),
            false => trans_choice('Switched off :count :connection tool.|Switched off :count :connection tools.', $count, $replace),
            null => trans_choice(':count :connection tool follows the policy again.|:count :connection tools follow the policy again.', $count, $replace),
        });
    }

    /**
     * Switch every tool the filter shows in one risk group of one Connection
     * on (`on`) or off (`off`), or let them follow the policy again (`reset`).
     */
    public function switchGroup(int $connectionId, string $risk, string $choice, SwitchStarTools $switchStarTools): void
    {
        $enabled = $this->choice($choice);
        $risk = ToolRisk::tryFrom($risk) ?? abort(404);

        $connection = $this->star->connections()->findOrFail($connectionId);

        $group = collect($this->groups)->firstWhere('connection.id', $connection->id);
        $toolNames = $this->toolNames(collect($group['risks'] ?? [])->firstWhere('risk', $risk)['tools'] ?? []);
        $count = count($toolNames);

        $switchStarTools->handle($this->star, $connection, $enabled, $toolNames);

        $this->forgetTools();

        $replace = ['connection' => $connection->name, 'risk' => $risk->label()];

        Flux::toast(variant: 'success', text: match ($enabled) {
            true => trans_choice('Switched on :count :connection tool (:risk).|Switched on :count :connection tools (:risk).', $count, $replace),
            false => trans_choice('Switched off :count :connection tool (:risk).|Switched off :count :connection tools (:risk).', $count, $replace),
            null => trans_choice(':count :connection tool (:risk) follows the policy again.|:count :connection tools (:risk) follow the policy again.', $count, $replace),
        });
    }

    /**
     * The switch a bulk choice gives: on (`on`), off (`off`) or none, so the
     * policy decides (`reset`).
     */
    private function choice(string $choice): ?bool
    {
        return match ($choice) {
            'on' => true,
            'off' => false,
            'reset' => null,
            default => abort(404),
        };
    }

    /**
     * The tools' names in their Connection's catalog.
     *
     * @param  list<StarTool>  $tools
     * @return list<string>
     */
    private function toolNames(array $tools): array
    {
        return array_map(fn (StarTool $tool): string => $tool->tool->name, $tools);
    }

    /**
     * The tools in their risk groups, safest first, leaving out empty groups,
     * with how many of each are on and how many have the user's own switch.
     *
     * @param  list<StarTool>  $tools
     * @return list<array{risk: ToolRisk, tools: list<StarTool>, enabled: int, ownChoices: int}>
     */
    private function riskGroups(array $tools): array
    {
        $byRisk = [];

        foreach ($tools as $tool) {
            $byRisk[ToolRisk::of($tool->tool)->value][] = $tool;
        }

        $groups = [];

        foreach (ToolRisk::cases() as $risk) {
            if (isset($byRisk[$risk->value])) {
                $groups[] = [
                    'risk' => $risk,
                    'tools' => $byRisk[$risk->value],
                    'enabled' => count(array_filter($byRisk[$risk->value], fn (StarTool $tool): bool => $tool->enabled)),
                    'ownChoices' => count(array_filter($byRisk[$risk->value], fn (StarTool $tool): bool => ! $tool->followsPolicy())),
                ];
            }
        }

        return $groups;
    }

    /**
     * One of the Star's tools as it exposes it, by its catalog id.
     */
    private function starTool(int $toolId): ?StarTool
    {
        return array_find($this->starTools, fn (StarTool $tool): bool => $tool->tool->id === $toolId);
    }

    /**
     * One of the Star's tools, by its catalog id.
     */
    private function findTool(int $toolId): ConnectionTool
    {
        return ConnectionTool::query()
            ->whereKey($toolId)
            ->whereIn('connection_id', $this->star->connections()->pluck('connections.id'))
            ->with('connection')
            ->firstOrFail();
    }

    /**
     * Whether the filter shows the tool: its exposed name or title contains the search.
     */
    private function isShown(StarTool $tool): bool
    {
        $search = trim($this->search);

        return $search === ''
            || Str::contains($tool->name, $search, ignoreCase: true)
            || ($tool->tool->title !== null && Str::contains($tool->tool->title, $search, ignoreCase: true));
    }

    private function forgetTools(): void
    {
        unset($this->starTools, $this->groups, $this->detailsTool, $this->toolDetails);
    }
};
