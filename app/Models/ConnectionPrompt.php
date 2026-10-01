<?php

namespace App\Models;

use Database\Factories\ConnectionPromptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use stdClass;

/**
 * A prompt the downstream server advertised, cached from its last
 * prompts/list. Prompts are templates a person invokes (a slash command in
 * Claude Code); they return instructions and never act on their own.
 */
#[Fillable(['connection_id', 'name', 'title', 'description', 'definition', 'definition_hash'])]
class ConnectionPrompt extends Model
{
    /** @use HasFactory<ConnectionPromptFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    /**
     * The definition decoded with JSON objects kept as objects.
     */
    public function definitionObject(): stdClass
    {
        $decoded = json_decode($this->definition, false);

        return $decoded instanceof stdClass ? $decoded : new stdClass;
    }

    /**
     * @return list<array{name: string, description?: string, required?: bool}>
     */
    public function arguments(): array
    {
        $arguments = json_decode($this->definition, true)['arguments'] ?? [];

        return is_array($arguments) ? array_values(array_filter($arguments, fn (mixed $argument): bool => is_array($argument) && is_string($argument['name'] ?? null))) : [];
    }
}
