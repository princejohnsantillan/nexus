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
 */
final readonly class StarTool
{
    /**
     * @param  string  $name  The exposed name, `{handle}__{tool}`.
     * @param  bool|null  $switch  The user's own switch, or null when the policy decides.
     */
    public function __construct(
        public Connection $connection,
        public ConnectionTool $tool,
        public string $name,
        public bool $enabled,
        public ?bool $switch,
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
     * The tool's definition as clients see it: the exact JSON its server
     * sent, schemas and annotations untouched, with the exposed name in
     * place of the server's.
     */
    public function definition(): string
    {
        return RawJson::withMember($this->tool->definition, 'name', json_encode($this->name, JSON_THROW_ON_ERROR))
            ?? throw new UnexpectedValueException("The stored definition of tool {$this->tool->id} is not a JSON object with a name.");
    }
}
