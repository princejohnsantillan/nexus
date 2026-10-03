<?php

declare(strict_types=1);

namespace App\Stars;

use App\Enums\GettingStartedStep;
use App\Models\Star;

/**
 * Where a user is on the getting-started checklist, and what did each step
 * that is done. App\Stars\GettingStarted works it out.
 */
final readonly class GettingStartedChecklist
{
    /**
     * @param  list<string>  $connectionNames  The names of the user's Connections, A to Z, each once.
     * @param  Star|null  $star  The Star the steps after creating one are about: the newest with a client set up, or else the newest. Null when the user has none.
     * @param  string|null  $client  What set up a client for that Star: the name of its newest connected app or token, or its signed URL. Null when no Star has a client.
     * @param  Star|null  $calledStar  The newest Star that has been called, or null when none has.
     */
    public function __construct(
        public array $connectionNames,
        public ?Star $star,
        public ?string $client,
        public ?Star $calledStar,
    ) {}

    public function isDone(GettingStartedStep $step): bool
    {
        return $this->doneWith($step) !== null;
    }

    /**
     * What did the step, to show beside it: the Connections' names, the
     * Star's name, the client, or the name of the Star that was called.
     * Null while the step isn't done.
     */
    public function doneWith(GettingStartedStep $step): ?string
    {
        return match ($step) {
            GettingStartedStep::ConnectServer => $this->connectionNames === [] ? null : implode(', ', $this->connectionNames),
            GettingStartedStep::CreateStar => $this->star?->name,
            GettingStartedStep::SetUpClient => $this->client,
            GettingStartedStep::ReceiveFirstCall => $this->calledStar?->name,
        };
    }

    /**
     * How many of the steps are done.
     */
    public function doneCount(): int
    {
        return count(array_filter(GettingStartedStep::cases(), $this->isDone(...)));
    }

    /**
     * The first step that isn't done, which the checklist points to next,
     * or null when every step is done.
     */
    public function nextStep(): ?GettingStartedStep
    {
        foreach (GettingStartedStep::cases() as $step) {
            if (! $this->isDone($step)) {
                return $step;
            }
        }

        return null;
    }

    public function isComplete(): bool
    {
        return ! $this->nextStep() instanceof GettingStartedStep;
    }
}
