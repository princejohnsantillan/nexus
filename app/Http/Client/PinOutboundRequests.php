<?php

namespace App\Http\Client;

use App\Security\OutboundGuard;
use Closure;
use Psr\Http\Message\RequestInterface;

/**
 * Guzzle middleware that checks every outbound request with the
 * OutboundGuard and pins it to the address the guard approved, so the
 * hostname can't re-resolve to an internal IP between check and connect.
 *
 * Pinning uses CURLOPT_RESOLVE, which only the cURL handler honours. Guzzle
 * sends streamed requests to its stream handler instead, so pinned requests
 * are not streamed. Nothing is lost: laravel/mcp reads the whole response
 * before parsing it anyway.
 */
class PinOutboundRequests
{
    public function __construct(protected OutboundGuard $guard) {}

    public function __invoke(callable $handler): Closure
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            $target = $this->guard->check((string) $request->getUri());

            if ($target['ip'] === null || ! function_exists('curl_exec')) {
                return $handler($request, $options);
            }

            $ip = str_contains($target['ip'], ':') ? "[{$target['ip']}]" : $target['ip'];

            $options['stream'] = false;
            $options['curl'][CURLOPT_RESOLVE] = ["{$target['host']}:{$target['port']}:{$ip}"];

            return $handler($request, $options);
        };
    }
}
