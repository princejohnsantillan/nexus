<?php

declare(strict_types=1);

namespace App\Billing;

use App\Concerns\KeepsSecretsInMemory;
use SensitiveParameter;

/**
 * A webhook registered with PayMongo: where it sends which events, whether
 * it is enabled, and in which mode, with the secret its deliveries are
 * signed with when PayMongo sends it (it does when registering and when
 * listing). Only the command that registers one ever shows its secret.
 */
final readonly class PayMongoWebhook
{
    use KeepsSecretsInMemory;

    /**
     * @param  list<string>  $events
     */
    public function __construct(
        public string $id,
        public string $url,
        public array $events,
        public string $status,
        public bool $livemode,
        #[SensitiveParameter]
        public ?string $secretKey = null,
    ) {}

    /**
     * The webhook in a PayMongo response's `data`, or null when it isn't one.
     *
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $id = $data['id'] ?? null;
        $attributes = $data['attributes'] ?? null;

        if (! is_string($id) || preg_match('/\Ahook_[A-Za-z0-9]+\z/', $id) !== 1 || ! is_array($attributes)) {
            return null;
        }

        $url = $attributes['url'] ?? null;
        $events = $attributes['events'] ?? null;
        $status = $attributes['status'] ?? null;
        $livemode = $attributes['livemode'] ?? null;
        $secretKey = $attributes['secret_key'] ?? null;

        if (! is_string($url) || ! is_array($events) || ! is_string($status) || ! is_bool($livemode)) {
            return null;
        }

        return new self(
            id: $id,
            url: $url,
            events: array_values(array_filter($events, is_string(...))),
            status: $status,
            livemode: $livemode,
            secretKey: is_string($secretKey) && $secretKey !== '' ? $secretKey : null,
        );
    }

    public function isEnabled(): bool
    {
        return $this->status === 'enabled';
    }
}
