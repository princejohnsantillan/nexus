<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ConnectionToolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use stdClass;

/**
 * One tool in a Connection's catalog, as its server listed it at the last refresh.
 *
 * `definition` is the tool's JSON exactly as received, objects kept as
 * objects; `definition_hash` is its SHA-256, so a refresh can tell when it
 * changed. The four hints are what the server declared in its annotations,
 * or null when it didn't say.
 *
 * @property int $id
 * @property int $connection_id
 * @property string $name
 * @property string|null $title
 * @property string|null $description
 * @property string $definition
 * @property string $definition_hash
 * @property bool|null $read_only
 * @property bool|null $destructive
 * @property bool|null $idempotent
 * @property bool|null $open_world
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Connection $connection
 *
 * @method static \Database\Factories\ConnectionToolFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereConnectionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereDefinition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereDefinitionHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereDestructive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereIdempotent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereOpenWorld($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereReadOnly($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['name', 'title', 'description', 'definition', 'definition_hash', 'read_only', 'destructive', 'idempotent', 'open_world'])]
class ConnectionTool extends Model
{
    /** @use HasFactory<ConnectionToolFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_only' => 'boolean',
            'destructive' => 'boolean',
            'idempotent' => 'boolean',
            'open_world' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    /**
     * The parameters the tool's input schema declares, in its order: each
     * property's name, type, whether the schema requires it, and its
     * description (or else its title). Nested schemas aren't opened up, so
     * an object is "object" and an array of them "object[]". A schema that
     * isn't an object or lists no properties gives none. NUL characters,
     * which some databases refuse in text, are dropped.
     *
     * @return list<array{name: string, type: string, required: bool, description: string|null}>
     */
    public function parameters(): array
    {
        $definition = json_decode($this->definition);
        $schema = $definition instanceof stdClass ? $definition->inputSchema ?? null : null;
        $properties = $schema instanceof stdClass ? $schema->properties ?? null : null;

        if (! $schema instanceof stdClass || ! $properties instanceof stdClass) {
            return [];
        }

        $required = is_array($schema->required ?? null) ? $schema->required : [];
        $parameters = [];

        foreach (get_object_vars($properties) as $name => $property) {
            $name = $this->text((string) $name);

            if ($name !== null) {
                $parameters[] = [
                    'name' => $name,
                    'type' => $this->typeOf($property),
                    'required' => in_array($name, $required, true),
                    'description' => $property instanceof stdClass ? $this->text($property->description ?? null) ?? $this->text($property->title ?? null) : null,
                ];
            }
        }

        return $parameters;
    }

    /**
     * A property's type as its schema states it: its `type`, or each of a
     * list of them ("string | null"), else the type of each of its `anyOf`
     * (or `oneOf`) choices, else "object" when it lists properties, else
     * "any". An array names the type of its items when they state one
     * ("string[]").
     */
    private function typeOf(mixed $schema): string
    {
        if (! $schema instanceof stdClass) {
            return 'any';
        }

        $items = $schema->items ?? null;
        $itemTypes = $items instanceof stdClass ? $this->types($items->type ?? null) : [];

        $types = array_map(
            fn (string $type): string => $type === 'array' && count($itemTypes) === 1 ? $itemTypes[0].'[]' : $type,
            $this->types($schema->type ?? null),
        );

        $choices = $schema->anyOf ?? $schema->oneOf ?? null;

        if ($types === [] && is_array($choices)) {
            $types = array_values(array_diff(array_map($this->typeOf(...), $choices), ['any']));
        }

        if ($types === []) {
            return isset($schema->properties) ? 'object' : 'any';
        }

        return implode(' | ', array_unique($types));
    }

    /**
     * The types a schema's `type` names: one, or each of a list of them.
     *
     * @return list<string>
     */
    private function types(mixed $type): array
    {
        $types = [];

        foreach (is_array($type) ? $type : [$type] as $name) {
            $name = $this->text($name);

            if ($name !== null) {
                $types[] = Str::limit($name, 40);
            }
        }

        return $types;
    }

    /**
     * Text the server sent, without NUL characters, or null when it isn't
     * text or is empty.
     */
    private function text(mixed $value): ?string
    {
        $value = is_string($value) ? str_replace("\0", '', $value) : '';

        return $value === '' ? null : $value;
    }
}
