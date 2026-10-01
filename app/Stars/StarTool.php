<?php

declare(strict_types=1);

namespace App\Stars;

use App\Downstream\RawJson;
use App\Models\Connection;
use App\Models\ConnectionTool;
use UnexpectedValueException;

/**
 * One tool of a Connection as a Star exposes it: the name clients call it
 * by, whether it is on, and whether that is the user's own choice or the
 * Star's new-tool policy.
 *
 * When the Star includes another Connection of the same service (a
 * sibling), the tool's description starts with its account label, so an
 * agent can pick between github__get_me and github-2__get_me.
 */
final readonly class StarTool
{
    /**
     * @param  string  $name  The exposed name, `{handle}__{tool}`.
     * @param  bool|null  $switch  The user's own switch, or null when the policy decides.
     * @param  bool  $hasSiblings  Whether the Star includes another Connection of the same service.
     */
    public function __construct(
        public Connection $connection,
        public ConnectionTool $tool,
        public string $name,
        public bool $enabled,
        public ?bool $switch,
        public bool $hasSiblings = false,
    ) {}

    /**
     * Whether the Star's new-tool policy decides this tool, because the user
     * hasn't switched it themselves.
     */
    public function followsPolicy(): bool
    {
        return $this->switch === null;
    }

    /**
     * The description clients see: the server's own, led by the account
     * label when the Star has a sibling ("From GitHub · octocat — use for:
     * work"), first so that clients that cut long descriptions keep it.
     */
    public function description(): ?string
    {
        $member = RawJson::member($this->tool->definition, 'description');
        $description = $member === null ? null : json_decode($member);
        $description = is_string($description) && $description !== '' ? $description : null;

        if (! $this->hasSiblings) {
            return $description;
        }

        $label = __('From :label', ['label' => $this->connection->accountLabel()]);

        return $description === null ? $label : $label."\n\n".$description;
    }

    /**
     * The tool's definition as clients see it: the exact JSON its server
     * sent, schemas and annotations untouched, with the exposed name in
     * place of the server's, and the description led by the account label
     * when the Star has a sibling.
     */
    public function definition(): string
    {
        $definition = RawJson::withMember($this->tool->definition, 'name', json_encode($this->name, JSON_THROW_ON_ERROR));

        if ($definition !== null && $this->hasSiblings) {
            $definition = RawJson::put($definition, 'description', json_encode($this->description(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return $definition
            ?? throw new UnexpectedValueException("The stored definition of tool {$this->tool->id} is not a JSON object with a name.");
    }
}
