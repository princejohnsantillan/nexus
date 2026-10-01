<?php

declare(strict_types=1);

namespace App\Stars;

use App\Downstream\RawJson;
use App\Models\Connection;
use App\Models\ConnectionPrompt;
use UnexpectedValueException;

/**
 * One prompt of a Connection as a Star exposes it: the name clients get it
 * by, whether it is on, and whether that is the user's own choice.
 *
 * When the Star includes another Connection of the same service (a
 * sibling), the prompt's description starts with its account label, as a
 * tool's does, so a person can pick between github__triage and
 * github-2__triage.
 */
final readonly class StarPrompt
{
    /**
     * @param  string  $name  The exposed name, `{handle}__{prompt}`.
     * @param  bool|null  $switch  The user's own switch, or null when it is on by default.
     * @param  bool  $hasSiblings  Whether the Star includes another Connection of the same service.
     */
    public function __construct(
        public Connection $connection,
        public ConnectionPrompt $prompt,
        public string $name,
        public bool $enabled,
        public ?bool $switch,
        public bool $hasSiblings = false,
    ) {}

    /**
     * Whether the prompt is on by default, because the user hasn't switched it themselves.
     */
    public function followsDefault(): bool
    {
        return $this->switch === null;
    }

    /**
     * The description clients see: the server's own, led by the account
     * label when the Star has a sibling ("From GitHub · octocat — use for:
     * work").
     */
    public function description(): ?string
    {
        $member = RawJson::member($this->prompt->definition, 'description');
        $description = $member === null ? null : json_decode($member);
        $description = is_string($description) && $description !== '' ? $description : null;

        if (! $this->hasSiblings) {
            return $description;
        }

        $label = __('From :label', ['label' => $this->connection->accountLabel()]);

        return $description === null ? $label : $label."\n\n".$description;
    }

    /**
     * The prompt's definition as clients see it in `prompts/list`: the exact
     * JSON its server sent, arguments untouched, with the exposed name in
     * place of the server's, and the description led by the account label
     * when the Star has a sibling.
     */
    public function definition(): string
    {
        $definition = RawJson::withMember($this->prompt->definition, 'name', json_encode($this->name, JSON_THROW_ON_ERROR));

        if ($definition !== null && $this->hasSiblings) {
            $definition = RawJson::put($definition, 'description', json_encode($this->description(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return $definition
            ?? throw new UnexpectedValueException("The stored definition of prompt {$this->prompt->id} is not a JSON object with a name.");
    }
}
