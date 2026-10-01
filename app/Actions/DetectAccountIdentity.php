<?php

declare(strict_types=1);

namespace App\Actions;

use App\Connectors\ConnectorProfileTool;
use App\Downstream\DownstreamSession;
use App\Exceptions\DownstreamRequestFailed;
use App\Models\Connection;
use Illuminate\Support\Arr;
use SensitiveParameter;
use stdClass;

/**
 * Works out which account a Connection signed in as, so the user and their
 * agents can tell two Connections of the same service apart:
 *
 *     $identity = $detect->fromTokenResponse($response);                   // an OAuth sign-in, e.g. Notion's workspace
 *     $identity = $detect->fromProfileTool($connection, $session, $tools); // a connector's profile tool, e.g. GitHub's login
 *
 * Both are best effort and only label the Connection; nothing is ever
 * authorised by them. The server wrote what comes back, and it ends up on
 * pages and in agents' instructions and tool descriptions, so it is
 * flattened to one line of at most 100 characters.
 */
class DetectAccountIdentity
{
    public const int MAX_LENGTH = 100;

    /**
     * Where a token response, or the claims of the OpenID Connect ID token
     * in it, names the person who signed in, in order of preference.
     *
     * @var list<string>
     */
    private const array PERSON_FIELDS = ['email', 'preferred_username', 'login', 'username', 'user.email', 'user.login', 'user.username', 'user.name'];

    /**
     * Where a token response names the workspace or organization signed in
     * to, such as Notion's `workspace_name` or Slack's `team.name`.
     *
     * @var list<string>
     */
    private const array WORKSPACE_FIELDS = ['workspace_name', 'workspace.name', 'team.name', 'team_name', 'organization.name', 'org.name'];

    /**
     * The account an OAuth token response names: the person, the workspace,
     * or both as "person @ workspace"; null when it names neither. The ID
     * token's claims are read without checking its signature, which is
     * fine for a label: the response came straight from the token endpoint.
     *
     * @param  array<array-key, mixed>  $response
     */
    public function fromTokenResponse(#[SensitiveParameter] array $response): ?string
    {
        $fields = [...$response, ...$this->idTokenClaims($response['id_token'] ?? null)];

        $parts = array_filter([
            $this->firstString($fields, self::PERSON_FIELDS),
            $this->firstString($fields, self::WORKSPACE_FIELDS),
        ]);

        return $this->clean(implode(' @ ', $parts));
    }

    /**
     * The account the Connection's profile tool names, when its connector
     * has one and the server listed it: the tool is called with no
     * arguments, and the account read from its structured content or from
     * JSON in its text. Null when there is none, or the call fails.
     *
     * @param  list<string>  $tools  The tools the server listed, as the JSON it sent.
     */
    public function fromProfileTool(Connection $connection, DownstreamSession $session, array $tools): ?string
    {
        $profileTool = $connection->connector()?->profileTool;

        if (! $profileTool instanceof ConnectorProfileTool || ! $this->lists($tools, $profileTool->name)) {
            return null;
        }

        try {
            $result = json_decode($session->callTool($profileTool->name, '{}'), true);
        } catch (DownstreamRequestFailed) {
            return null;
        }

        if (! is_array($result) || ($result['isError'] ?? false) === true) {
            return null;
        }

        foreach ($this->resultObjects($result) as $object) {
            $value = Arr::get($object, $profileTool->field);

            if (is_string($value) && ($identity = $this->clean($value)) !== null) {
                return $identity;
            }
        }

        return null;
    }

    /**
     * The value on one line, without control or invisible characters, cut
     * to 100 characters (ending with "…" when cut); null when nothing is left.
     */
    private function clean(string $value): ?string
    {
        $line = trim(preg_replace('/[\p{C}\p{Z}\s]+/u', ' ', $value) ?? '');

        if ($line === '') {
            return null;
        }

        return mb_strlen($line) <= self::MAX_LENGTH ? $line : rtrim(mb_substr($line, 0, self::MAX_LENGTH - 1)).'…';
    }

    /**
     * The first of the fields (dot paths) that holds a non-blank string.
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $paths
     */
    private function firstString(#[SensitiveParameter] array $data, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = Arr::get($data, $path);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * The claims of an ID token (a JWT), unverified; none when it isn't one.
     *
     * @return array<array-key, mixed>
     */
    private function idTokenClaims(#[SensitiveParameter] mixed $idToken): array
    {
        if (! is_string($idToken)) {
            return [];
        }

        $payload = base64_decode(strtr(explode('.', $idToken)[1] ?? '', '-_', '+/'), true);
        $claims = is_string($payload) ? json_decode($payload, true) : null;

        return is_array($claims) ? $claims : [];
    }

    /**
     * Whether the server listed a tool with this name.
     *
     * @param  list<string>  $tools
     */
    private function lists(array $tools, string $name): bool
    {
        foreach ($tools as $definition) {
            $tool = json_decode($definition);

            if ($tool instanceof stdClass && ($tool->name ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * The JSON objects a tool result carries: its structured content, then
     * each text block that holds a JSON object.
     *
     * @param  array<array-key, mixed>  $result
     * @return list<array<array-key, mixed>>
     */
    private function resultObjects(array $result): array
    {
        $objects = is_array($result['structuredContent'] ?? null) ? [$result['structuredContent']] : [];

        foreach (is_array($result['content'] ?? null) ? $result['content'] : [] as $block) {
            $text = is_array($block) && ($block['type'] ?? null) === 'text' ? $block['text'] ?? null : null;
            $object = is_string($text) ? json_decode($text, true) : null;

            if (is_array($object)) {
                $objects[] = $object;
            }
        }

        return $objects;
    }
}
