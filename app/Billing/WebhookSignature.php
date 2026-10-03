<?php

declare(strict_types=1);

namespace App\Billing;

use SensitiveParameter;

/**
 * The `Paymongo-Signature` header of a webhook delivery, `t=…,te=…,li=…`:
 * when PayMongo signed it, and its signature for a test-mode event (`te`)
 * or a live one (`li`), the other left empty. A signature is the
 * HMAC-SHA256 of "{t}.{the raw body}", keyed with the webhook's secret.
 *
 * The time isn't checked against the clock: a replayed delivery can only
 * ask Nexus to confirm a payment with PayMongo again, which changes nothing.
 */
final readonly class WebhookSignature
{
    private function __construct(
        private string $timestamp,
        private string $test,
        private string $live,
    ) {}

    /**
     * The header's parts, or null when it has no time.
     */
    public static function fromHeader(?string $header): ?self
    {
        $parts = [];

        foreach (explode(',', $header ?? '') as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$name] = $value;
        }

        $timestamp = $parts['t'] ?? '';

        if (preg_match('/\A[0-9]+\z/', $timestamp) !== 1) {
            return null;
        }

        return new self($timestamp, $parts['te'] ?? '', $parts['li'] ?? '');
    }

    /**
     * Whether it signs the body with the secret in the event's own mode:
     * a test-mode event is checked against `te` and a live one against
     * `li`, so a signature for the other mode never counts.
     */
    public function signs(string $payload, #[SensitiveParameter] string $secret, bool $livemode): bool
    {
        $signature = $livemode ? $this->live : $this->test;

        return $signature !== '' && hash_equals(hash_hmac('sha256', "{$this->timestamp}.{$payload}", $secret), $signature);
    }
}
