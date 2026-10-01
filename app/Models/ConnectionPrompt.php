<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ConnectionPromptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use stdClass;

/**
 * One prompt in a Connection's catalog, as its server listed it at the last
 * refresh. A prompt is a template a person picks, such as a slash command
 * in Claude Code: it returns messages for the agent and does nothing itself.
 *
 * `definition` is the prompt's JSON exactly as received, objects kept as
 * objects; `definition_hash` is its SHA-256, so a refresh can tell when it
 * changed.
 *
 * @property int $id
 * @property int $connection_id
 * @property string $name
 * @property string|null $title
 * @property string|null $description
 * @property string $definition
 * @property string $definition_hash
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Connection $connection
 *
 * @method static \Database\Factories\ConnectionPromptFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionPrompt newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionPrompt newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionPrompt query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionPrompt whereConnectionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionPrompt whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionPrompt whereDefinition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionPrompt whereDefinitionHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionPrompt whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionPrompt whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionPrompt whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionPrompt whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionPrompt whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['name', 'title', 'description', 'definition', 'definition_hash'])]
class ConnectionPrompt extends Model
{
    /** @use HasFactory<ConnectionPromptFactory> */
    use HasFactory;

    /**
     * The longest prompt name Nexus keeps, in characters. The MCP
     * specification sets no limit; this is Nexus's own, the same as for
     * tool names, and the length of the name columns (here, in Stars'
     * prompt switches and in activity entries). A prompt with a longer
     * name is skipped.
     */
    public const int NAME_MAX_LENGTH = 128;

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    /**
     * The arguments the server declared for the prompt, in its order; those
     * without a name are skipped. NUL characters, which some databases refuse
     * in text, are dropped.
     *
     * @return list<array{name: string, title: string|null, description: string|null, required: bool}>
     */
    public function arguments(): array
    {
        $definition = json_decode($this->definition);
        $declared = $definition instanceof stdClass && is_array($definition->arguments ?? null) ? $definition->arguments : [];

        $text = function (mixed $value): ?string {
            $value = is_string($value) ? str_replace("\0", '', $value) : '';

            return $value === '' ? null : $value;
        };

        $arguments = [];

        foreach ($declared as $argument) {
            $name = $argument instanceof stdClass ? $text($argument->name ?? null) : null;

            if ($argument instanceof stdClass && $name !== null) {
                $arguments[] = [
                    'name' => $name,
                    'title' => $text($argument->title ?? null),
                    'description' => $text($argument->description ?? null),
                    'required' => ($argument->required ?? false) === true,
                ];
            }
        }

        return $arguments;
    }
}
