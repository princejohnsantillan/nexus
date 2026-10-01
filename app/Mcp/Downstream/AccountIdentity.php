<?php

namespace App\Mcp\Downstream;

use App\Models\Connection;
use Illuminate\Support\Str;
use stdClass;

/**
 * Works out which account a connection is signed in as, so the owner (and
 * the agent) can tell two connections to the same service apart.
 *
 * Two sources, both best-effort and for display only, never for
 * authorization:
 *
 * - The OAuth token response: OpenID Connect id_token claims, or account
 *   fields some providers add (Slack includes the workspace).
 * - A profile tool on the server ("whoami", "get_me", or one marked with
 *   ChatGPT's `openai/profile` meta), called with no arguments.
 *
 * Whatever comes back is written by the downstream server and ends up in
 * prompts, so it is flattened to one short line.
 */
class AccountIdentity
{
    public const MAX_LENGTH = 100;

    /** Tool names that conventionally return the signed-in account. */
    protected const PROFILE_TOOL_NAMES = ['whoami', 'get_me', 'get_self', 'get_current_user', 'current_user', 'get_profile', 'auth_test'];

    protected const PERSON_FIELDS = ['email', 'user.email', 'preferred_username', 'login', 'username', 'user.login', 'user.username', 'name', 'user.name', 'user.real_name'];

    protected const ORGANISATION_FIELDS = ['team.name', 'workspace.name', 'workspace_name', 'team_name', 'organization.name', 'org.name', 'enterprise.name'];

    /**
     * @param  array<string, mixed>  $response
     */
    public static function fromTokenResponse(array $response): ?string
    {
        $claims = is_string($response['id_token'] ?? null) ? static::unverifiedJwtClaims($response['id_token']) : [];

        return static::describe([...$response, ...$claims]);
    }

    /**
     * @param  list<stdClass>  $tools
     */
    public static function profileTool(array $tools): ?stdClass
    {
        foreach ($tools as $tool) {
            if (($tool->_meta->{'openai/profile'} ?? false) === true) {
                return $tool;
            }
        }

        foreach ($tools as $tool) {
            if (static::looksLikeProfileTool($tool)) {
                return $tool;
            }
        }

        return null;
    }

    public static function fromToolResult(stdClass $result): ?string
    {
        if (($result->isError ?? false) === true) {
            return null;
        }

        if (($result->structuredContent ?? null) instanceof stdClass) {
            $described = static::describe(json_decode((string) json_encode($result->structuredContent), true) ?: []);

            if ($described !== null) {
                return $described;
            }
        }

        foreach (is_array($result->content ?? null) ? $result->content : [] as $item) {
            if (($item->type ?? null) !== 'text' || ! is_string($item->text ?? null) || trim($item->text) === '') {
                continue;
            }

            $decoded = json_decode($item->text, true);

            if (is_array($decoded)) {
                return static::describe($decoded);
            }

            return static::clean(strtok(trim($item->text), "\n") ?: '');
        }

        return null;
    }

    /**
     * Another of the owner's connections to the same service that is signed
     * in as the very same account, which usually means the provider reused
     * a browser session instead of asking which account to use.
     */
    public static function sameAccountAs(Connection $connection): ?Connection
    {
        if (blank($connection->account_identity)) {
            return null;
        }

        return Connection::query()
            ->where('user_id', $connection->user_id)
            ->whereKeyNot($connection->getKey())
            ->where('account_identity', $connection->account_identity)
            ->get()
            ->first(fn (Connection $other): bool => $other->serviceKey() === $connection->serviceKey());
    }

    public static function clean(string $value): ?string
    {
        $line = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));

        return $line === '' ? null : Str::limit($line, self::MAX_LENGTH, '…');
    }

    /**
     * "person @ organisation", from whichever common fields are present.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function describe(array $data): ?string
    {
        $parts = array_filter([
            static::firstString($data, self::PERSON_FIELDS),
            static::firstString($data, self::ORGANISATION_FIELDS),
        ]);

        return $parts === [] ? null : static::clean(implode(' @ ', $parts));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $paths
     */
    protected static function firstString(array $data, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($data, $path);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    protected static function looksLikeProfileTool(stdClass $tool): bool
    {
        $name = str_replace(['-', '.'], '_', strtolower((string) ($tool->name ?? '')));
        $matchesName = collect(self::PROFILE_TOOL_NAMES)->contains(
            fn (string $candidate): bool => $name === $candidate || str_ends_with($name, '_'.$candidate),
        );

        $required = $tool->inputSchema->required ?? [];

        return $matchesName
            && (! is_array($required) || $required === [])
            && ($tool->annotations->readOnlyHint ?? null) !== false
            && ($tool->annotations->destructiveHint ?? null) !== true;
    }

    /**
     * Claims are read without verifying the signature. That is fine here:
     * the result is a display label, and the token came straight from the
     * authorization server over TLS.
     *
     * @return array<string, mixed>
     */
    protected static function unverifiedJwtClaims(string $jwt): array
    {
        $payload = explode('.', $jwt)[1] ?? '';
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);

        return is_array($claims) ? $claims : [];
    }
}
