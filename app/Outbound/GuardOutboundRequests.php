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
 * Redirects are never followed, since a redirect could point anywhere. They
 * are switched off for every request by the global HTTP client options, and
 * because Guzzle's redirect middleware runs before this one, a request that
 * switches them back on is refused here rather than sent.
 */
final readonly class GuardOutboundRequests
{
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

            $target = $this->guard->check((string) $request->getUri());

            $options['stream'] = false;

            $pin = $target->curlResolveEntry();

            if ($pin !== null) {
                $curl = is_array($options['curl'] ?? null) ? $options['curl'] : [];
                $curl[CURLOPT_RESOLVE] = [$pin];
                $options['curl'] = $curl;
            }

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
