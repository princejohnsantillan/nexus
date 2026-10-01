<?php

declare(strict_types=1);

namespace App\Actions;

use App\Downstream\DownstreamClient;
use App\Enums\ConnectionStatus;
use App\Enums\DownstreamFailure;
use App\Exceptions\CatalogNotStored;
use App\Exceptions\DownstreamRequestFailed;
use App\Models\Connection;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

class RefreshCatalog
{
    /**
     * Tool names the MCP specification allows.
     */
    private const string TOOL_NAME_PATTERN = '/^[A-Za-z0-9_.-]{1,128}$/';

    /**
     * The columns that say which server a refresh asks and how it signs in:
     * the URL, the sign-in method, its settings (such as the header name) and
     * its encrypted credentials, whose ciphertext changes with every new value.
     *
     * @var list<string>
     */
    private const array SIGN_IN_COLUMNS = ['url', 'auth_type', 'settings', 'secrets'];

    public function __construct(private readonly DownstreamClient $downstream) {}

    /**
     * Re-read a Connection's tools from its server and store them as its
     * catalog: tools are matched by name, changed ones are rewritten, vanished
     * ones are removed, and tools with names the MCP specification doesn't
     * allow are skipped. Each definition is stored as the exact JSON the
     * server sent. The Connection is then connected, with no last error.
     *
     * When the server can't be listed, the previous catalog stays, and the
     * Connection's status and last error (a Nexus-authored message) say why.
     *
     * Asking the server takes time, so the outcome is stored only if the
     * Connection still exists with the same server and sign-in, credentials
     * included; otherwise it no longer applies and is dropped. The Connection
     * must be saved: what is stored is what the refresh signs in with.
     *
     * @return bool Whether the tools loaded and were stored.
     */
    public function handle(Connection $connection): bool
    {
        $signIn = $this->signInOf($connection);

        try {
            $tools = $this->downstream->session($connection)->listTools();
        } catch (DownstreamRequestFailed $failed) {
            $this->storeIfCurrent($connection, $signIn, function () use ($connection, $failed): void {
                $connection->forceFill([
                    'status' => $failed->failure === DownstreamFailure::NeedsSignIn ? ConnectionStatus::NeedsAuth : ConnectionStatus::Error,
                    'last_error' => $failed->getMessage(),
                ])->save();
            });

            return false;
        }

        return $this->storeIfCurrent($connection, $signIn, function () use ($connection, $tools): void {
            $storedHashes = $connection->tools()->pluck('definition_hash', 'name');
            $names = [];

            foreach ($tools as $definition) {
                $tool = json_decode($definition);
                $name = $tool instanceof stdClass ? $tool->name ?? null : null;

                if (! $tool instanceof stdClass || ! is_string($name) || preg_match(self::TOOL_NAME_PATTERN, $name) !== 1) {
                    continue;
                }

                $names[] = $name;
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
    }

    /**
     * Store a refresh's outcome in one transaction, holding the Connection's
     * row, unless the Connection was deleted, or its server or sign-in
     * (credentials included) changed, while its server was being asked.
     *
     * A database error is reported as CatalogNotStored, without the values
     * being written (they came from the server), and recorded on the
     * Connection under the same condition.
     *
     * @param  list<mixed>  $signIn  What the refresh signed in with, from signInOf().
     * @param  Closure(): void  $store
     * @return bool Whether the outcome was stored.
     */
    private function storeIfCurrent(Connection $connection, array $signIn, Closure $store): bool
    {
        try {
            return $this->whileCurrent($connection, $signIn, $store);
        } catch (QueryException $exception) {
            report(CatalogNotStored::for($connection, $exception));

            $connection->discardChanges();

            $this->whileCurrent($connection, $signIn, function () use ($connection): void {
                $connection->forceFill([
                    'status' => ConnectionStatus::Error,
                    'last_error' => __('Nexus could not store the server\'s tools.'),
                ])->save();
            });

            return false;
        }
    }

    /**
     * Run a write in one transaction holding the Connection's row, if the
     * Connection still exists with the sign-in the refresh used.
     *
     * @param  list<mixed>  $signIn
     * @param  Closure(): void  $write
     * @return bool Whether the write ran.
     */
    private function whileCurrent(Connection $connection, array $signIn, Closure $write): bool
    {
        return DB::transaction(function () use ($connection, $signIn, $write): bool {
            $current = Connection::query()->whereKey($connection->id)->lockForUpdate()->first(['id', ...self::SIGN_IN_COLUMNS]);

            if ($current === null || $this->signInOf($current) !== $signIn) {
                return false;
            }

            $write();

            return true;
        });
    }

    /**
     * The Connection's sign-in columns as stored, ciphertext and all.
     *
     * @return list<mixed>
     */
    private function signInOf(Connection $connection): array
    {
        return array_map(fn (string $column): mixed => $connection->getRawOriginal($column), self::SIGN_IN_COLUMNS);
    }

    /**
     * The columns Nexus shows for a tool: its title and description, and the
     * behaviour hints its annotations declare, null for each one they don't.
     * NUL characters, which some databases refuse in text, are dropped.
     *
     * @return array{title: string|null, description: string|null, read_only: bool|null, destructive: bool|null, idempotent: bool|null, open_world: bool|null}
     */
    private function summarize(stdClass $tool): array
    {
        $annotations = ($tool->annotations ?? null) instanceof stdClass ? $tool->annotations : new stdClass;

        $hint = fn (string $name): ?bool => is_bool($annotations->{$name} ?? null) ? $annotations->{$name} : null;

        $text = function (mixed $value): ?string {
            $value = is_string($value) ? str_replace("\0", '', $value) : '';

            return $value === '' ? null : $value;
        };

        $title = $text($tool->title ?? null) ?? $text($annotations->title ?? null);

        return [
            'title' => $title === null ? null : Str::limit($title, 255, ''),
            'description' => $text($tool->description ?? null),
            'read_only' => $hint('readOnlyHint'),
            'destructive' => $hint('destructiveHint'),
            'idempotent' => $hint('idempotentHint'),
            'open_world' => $hint('openWorldHint'),
        ];
    }
}
