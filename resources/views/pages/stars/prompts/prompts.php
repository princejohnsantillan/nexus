<?php

declare(strict_types=1);

use App\Actions\SwitchStarPrompts;
use App\Models\Connection;
use App\Models\ConnectionPrompt;
use App\Models\Star;
use App\Stars\StarPrompt;
use App\Stars\StarPrompts;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Star prompts')] class extends Component
{
    public Star $star;

    /**
     * Each of the Star's Connections, by name, with its prompts and how many are on.
     *
     * @return list<array{connection: Connection, prompts: list<StarPrompt>, enabled: int}>
     */
    #[Computed]
    public function groups(): array
    {
        $prompts = [];

        foreach (resolve(StarPrompts::class)->prompts($this->star) as $prompt) {
            $prompts[$prompt->connection->id][] = $prompt;
        }

        $connections = $this->star->connections()->orderBy('name')->orderBy('connections.id')->get();

        return array_values($connections->map(fn (Connection $connection): array => [
            'connection' => $connection,
            'prompts' => $prompts[$connection->id] ?? [],
            'enabled' => count(array_filter($prompts[$connection->id] ?? [], fn (StarPrompt $prompt): bool => $prompt->enabled)),
        ])->all());
    }

    /**
     * Switch one prompt on or off as the user's own choice.
     */
    public function switchPrompt(int $promptId, bool $enabled, SwitchStarPrompts $switchStarPrompts): void
    {
        $prompt = $this->findPrompt($promptId);

        $switchStarPrompts->handle($this->star, $prompt->connection, $enabled, [$prompt->name]);

        $this->forgetPrompts();
    }

    /**
     * Let one prompt be on by default again.
     */
    public function resetPrompt(int $promptId, SwitchStarPrompts $switchStarPrompts): void
    {
        $prompt = $this->findPrompt($promptId);

        $switchStarPrompts->handle($this->star, $prompt->connection, null, [$prompt->name]);

        $this->forgetPrompts();
    }

    /**
     * Switch every prompt of one Connection on (`on`) or off (`off`), or let
     * them be on by default again (`reset`).
     */
    public function switchConnection(int $connectionId, string $choice, SwitchStarPrompts $switchStarPrompts): void
    {
        $enabled = match ($choice) {
            'on' => true,
            'off' => false,
            'reset' => null,
            default => abort(404),
        };

        $connection = $this->star->connections()->findOrFail($connectionId);
        $count = $connection->prompts()->count();

        $switchStarPrompts->handle($this->star, $connection, $enabled);

        $this->forgetPrompts();

        $replace = ['connection' => $connection->name];

        Flux::toast(variant: 'success', text: match ($enabled) {
            true => trans_choice('Switched on :count :connection prompt.|Switched on :count :connection prompts.', $count, $replace),
            false => trans_choice('Switched off :count :connection prompt.|Switched off :count :connection prompts.', $count, $replace),
            null => trans_choice(':count :connection prompt is on by default again.|:count :connection prompts are on by default again.', $count, $replace),
        });
    }

    /**
     * One of the Star's prompts, by its catalog id.
     */
    private function findPrompt(int $promptId): ConnectionPrompt
    {
        return ConnectionPrompt::query()
            ->whereKey($promptId)
            ->whereIn('connection_id', $this->star->connections()->pluck('connections.id'))
            ->with('connection')
            ->firstOrFail();
    }

    private function forgetPrompts(): void
    {
        unset($this->groups);
    }
};
