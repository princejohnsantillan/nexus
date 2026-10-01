<?php

namespace App\Mcp\Downstream;

use Laravel\Mcp\Client\Transport\HttpTransport;

/**
 * The laravel/mcp HTTP transport with two additions for real-world servers.
 *
 * - It keeps a copy of every raw message it hands to the client. The client
 *   decodes JSON into PHP arrays, which turns `{}` into `[]` and corrupts
 *   tool schemas and results, so Nexus re-decodes the raw messages itself.
 * - It readdresses errors that come back with a placeholder id (some SDKs
 *   answer an unsupported request with `"id": "server-error"`). Without
 *   this the client can't pair the error with its request, and never falls
 *   back from the 2026-07-28 probe to the older handshake.
 */
class RecordingHttpTransport extends HttpTransport
{
    /** @var list<string> */
    protected array $received = [];

    /**
     * @param  array<string, string>  $headers
     */
    public function send(string $message, array $headers = []): void
    {
        $queued = count($this->queue);

        parent::send($message, $headers);

        $requestId = json_decode($message, true)['id'] ?? null;

        if ($requestId === null) {
            return;
        }

        foreach (array_slice($this->queue, $queued, preserve_keys: true) as $index => $raw) {
            $response = json_decode($raw, true);

            if (is_array($response) && isset($response['error']) && ($response['id'] ?? null) !== $requestId) {
                $response['id'] = $requestId;
                $this->queue[$index] = (string) json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
    }

    public function receive(): string
    {
        return $this->received[] = parent::receive();
    }

    /**
     * Return the raw messages received since the last call, and forget them.
     *
     * @return list<string>
     */
    public function takeReceived(): array
    {
        [$received, $this->received] = [$this->received, []];

        return $received;
    }
}
