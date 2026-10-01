<?php

declare(strict_types=1);

namespace App\Actions;

use App\Downstream\DownstreamClient;
use App\Enums\ConnectionStatus;
use App\Enums\DownstreamFailure;
use App\Exceptions\DownstreamRequestFailed;
use App\Models\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

class RefreshCatalog
{
    /**
     * Tool names the MCP specification allows.
     */
    private const string TOOL_NAME_PATTERN = '/^[A-Za-z0-9_.-]{1,128}$/';

    public function __construct(private readonly DownstreamClient $downstream) {}

    /**
     * Re-read a Connection's tools from its server and store them as its
     * catalog: tools are matched by name, changed ones are rewritten, vanished
     * ones are removed, and tools with names the MCP specification doesn't
     * allow are skipped. The Connection is then connected, with no last error.
     *
     * When the server can't be listed, the previous catalog stays, and the
     * Connection's status and last error (a Nexus-authored message) say why.
     *
     * @return bool Whether the tools loaded.
     */
    public function handle(Connection $connection): bool
    {
        try {
            $tools = $this->downstream->session($connection)->listTools();
        } catch (DownstreamRequestFailed $failed) {
            $connection->forceFill([
                'status' => $failed->failure === DownstreamFailure::NeedsSignIn ? ConnectionStatus::NeedsAuth : ConnectionStatus::Error,
                'last_error' => $failed->getMessage(),
            ])->save();

            return false;
        }

        DB::transaction(function () use ($connection, $tools): void {
            $storedHashes = $connection->tools()->pluck('definition_hash', 'name');
            $names = [];

            foreach ($tools as $tool) {
                $name = $tool->name;

                if (! is_string($name) || preg_match(self::TOOL_NAME_PATTERN, $name) !== 1) {
                    continue;
                }

                $names[] = $name;
                $definition = json_encode($tool, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
                $hash = hash('sha256', $definition);

                if ($storedHashes->get($name) === $hash) {
                    continue;
                }

                $connection->tools()->updateOrCreate(['name' => $name], [
                    ...$this->summarize($tool),
                    'definition' => $definition,
                    'definition_hash' => $hash,
                ]);
            }

            $connection->tools()->whereNotIn('name', $names)->delete();

            $connection->forceFill([
                'status' => ConnectionStatus::Connected,
                'last_error' => null,
                'catalog_refreshed_at' => now(),
            ])->save();
        });

        return true;
    }

    /**
     * The columns Nexus shows for a tool: its title and description, and the
     * behaviour hints its annotations declare, null for each one they don't.
     *
     * @return array{title: string|null, description: string|null, read_only: bool|null, destructive: bool|null, idempotent: bool|null, open_world: bool|null}
     */
    private function summarize(stdClass $tool): array
    {
        $annotations = ($tool->annotations ?? null) instanceof stdClass ? $tool->annotations : new stdClass;

        $hint = fn (string $name): ?bool => is_bool($annotations->{$name} ?? null) ? $annotations->{$name} : null;

        $title = collect([$tool->title ?? null, $annotations->title ?? null])
            ->first(fn (mixed $title): bool => is_string($title) && $title !== '');
        $description = $tool->description ?? null;

        return [
            'title' => is_string($title) ? Str::limit($title, 255, '') : null,
            'description' => is_string($description) && $description !== '' ? $description : null,
            'read_only' => $hint('readOnlyHint'),
            'destructive' => $hint('destructiveHint'),
            'idempotent' => $hint('idempotentHint'),
            'open_world' => $hint('openWorldHint'),
        ];
    }
}
