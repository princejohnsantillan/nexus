<?php

declare(strict_types=1);

namespace App\Outbound;

use App\Exceptions\OutboundRequestBlocked;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RedirectMiddleware;
use Psr\Http\Message\RequestInterface;

/**
 * HTTP client middleware that sends every request through the outbound guard.
 *
 * A request that passes is pinned to the addresses the guard approved with
 * curl's resolve option, so DNS cannot change between the check and the
 * connection. Only curl's handler honours that option and Guzzle hands
 * streamed transfers to its stream handler, so transfers are never streamed.
 *
 * Requests always connect directly. A proxy resolves the host itself, which
 * would bypass the pin, and Guzzle picks proxies up from the environment
 * (HTTPS_PROXY, ALL_PROXY and friends) as well as from the request options,
 * so every request gets an empty proxy: Guzzle's final "no proxy" decision.
 * A request carrying curl options that connect somewhere else is refused.
 *
 * Redirects are never followed, since a redirect could point anywhere. They
 * are switched off for every request by the global HTTP client options, and
 * because Guzzle's redirect middleware runs before this one, a request that
 * switches them back on is refused here rather than sent.
 */
final readonly class GuardOutboundRequests
{
    /**
     * Curl options that would connect somewhere other than the approved addresses.
     *
     * @var list<int>
     */
    private const array REROUTING_CURL_OPTIONS = [
        CURLOPT_CONNECT_TO,
        CURLOPT_UNIX_SOCKET_PATH,
    ];

    public function __construct(private OutboundGuard $guard) {}

    /**
     * @param  callable(RequestInterface, array<array-key, mixed>): PromiseInterface  $handler
     * @return Closure(RequestInterface, array<array-key, mixed>): PromiseInterface
     */
    public function __invoke(callable $handler): Closure
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            if ($this->followsRedirects($options)) {
                throw new OutboundRequestBlocked('Nexus does not follow redirects.');
            }

            $curl = is_array($options['curl'] ?? null) ? $options['curl'] : [];

            if (array_intersect_key($curl, array_flip(self::REROUTING_CURL_OPTIONS)) !== []) {
                throw new OutboundRequestBlocked('Nexus only connects to the addresses it approved.');
            }

            $target = $this->guard->check((string) $request->getUri());

            $pin = $target->curlResolveEntry();

            if ($pin !== null) {
                $curl[CURLOPT_RESOLVE] = [$pin];
            }

            $options['curl'] = $curl;
            $options['proxy'] = '';
            $options['stream'] = false;

            return $handler($request, $options);
        };
    }

    /**
     * Whether Guzzle would follow a redirect response to this request.
     *
     * @param  array<array-key, mixed>  $options
     */
    private function followsRedirects(array $options): bool
    {
        $redirects = $options['allow_redirects'] ?? false;

        if (is_array($redirects)) {
            return ! empty(($redirects + RedirectMiddleware::DEFAULT_SETTINGS)['max']);
        }

        return $redirects === true;
    }
}
