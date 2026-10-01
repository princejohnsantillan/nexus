<?php

declare(strict_types=1);

namespace App\ConnectionOAuth;

use App\Exceptions\ConnectionSignInFailed;
use App\Exceptions\OutboundRequestBlocked;
use Closure;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * Requests to servers' OAuth endpoints: metadata, registration and tokens.
 *
 * They go through Laravel's HTTP client, so the outbound guard checks and
 * pins every one, and they ask for JSON within the downstream connect
 * timeout. A server Nexus can't reach fails with ConnectionSignInFailed;
 * any answer, whatever its status, is the caller's to judge.
 */
final readonly class OAuthRequests
{
    /**
     * Fetch a JSON object, such as a metadata document; null when the server
     * answers with anything other than one, successfully.
     *
     * @return array<string, mixed>|null
     *
     * @throws ConnectionSignInFailed when the server can't be reached
     */
    public function getJson(string $url): ?array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get($url));

        return $response->successful() ? self::json($response) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ConnectionSignInFailed when the server can't be reached
     */
    public function postJson(string $url, array $data): Response
    {
        return $this->send(fn (PendingRequest $request): Response => $request->asJson()->post($url, $data));
    }

    /**
     * POST a form, such as a token request, optionally authenticating the
     * client with HTTP Basic.
     *
     * @param  array<string, mixed>  $data
     * @param  array{0: string, 1: string}|null  $basicAuth  The client ID and secret.
     *
     * @throws ConnectionSignInFailed when the server can't be reached
     */
    public function postForm(string $url, #[SensitiveParameter] array $data, #[SensitiveParameter] ?array $basicAuth = null): Response
    {
        return $this->send(function (PendingRequest $request) use ($url, $data, $basicAuth): Response {
            if ($basicAuth !== null) {
                $request->withBasicAuth(...$basicAuth);
            }

            return $request->asForm()->post($url, $data);
        });
    }

    /**
     * The JSON object a response holds, or null when it holds anything else.
     *
     * @return array<string, mixed>|null
     */
    public static function json(Response $response): ?array
    {
        $data = json_decode($response->body(), true);

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            return null;
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param  Closure(PendingRequest): Response  $request
     *
     * @throws ConnectionSignInFailed
     */
    private function send(Closure $request): Response
    {
        $timeout = config()->float('nexus.downstream.connect_timeout');

        try {
            return $request(Http::acceptJson()->connectTimeout($timeout)->timeout($timeout));
        } catch (OutboundRequestBlocked $blocked) {
            throw ConnectionSignInFailed::because(__('Nexus won\'t contact the server\'s sign-in service: :reason', ['reason' => $blocked->getMessage()]));
        } catch (HttpClientException) {
            throw ConnectionSignInFailed::because(__('Nexus couldn\'t reach the server\'s sign-in service.'));
        }
    }
}
