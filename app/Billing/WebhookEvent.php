<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * An event PayMongo delivered to the webhook, as far as Nexus needs it: its
 * type, its mode and, for a checkout session's event, the session's id and
 * reference number. Nothing else in it is trusted: whether a checkout was
 * paid is asked of PayMongo.
 *
 * PayMongo documents two shapes, and both are read: its event envelope,
 * with the event in `data.attributes` and the resource in
 * `data.attributes.data`, and the one its hosted checkout guide shows, with
 * the event in `data` and the resource in `data.data`.
 */
final readonly class WebhookEvent
{
    /**
     * A checkout session was paid.
     */
    public const string CHECKOUT_PAID = 'checkout_session.payment.paid';

    public function __construct(
        public string $type,
        public bool $livemode,
        public ?string $checkoutSessionId = null,
        public ?string $referenceNumber = null,
    ) {}

    /**
     * The event in a delivery's raw body, or null when it names no type and mode.
     */
    public static function fromPayload(string $payload): ?self
    {
        $body = json_decode($payload, true);
        $data = is_array($body) ? $body['data'] ?? null : null;

        if (! is_array($data)) {
            return null;
        }

        $event = is_array($data['attributes'] ?? null) && is_string($data['attributes']['type'] ?? null) ? $data['attributes'] : $data;
        $type = $event['type'] ?? null;
        $livemode = $event['livemode'] ?? null;

        if (! is_string($type) || ! is_bool($livemode)) {
            return null;
        }

        $resource = is_array($event['data'] ?? null) ? $event['data'] : [];
        $sessionId = $resource['id'] ?? null;
        $referenceNumber = is_array($resource['attributes'] ?? null) ? $resource['attributes']['reference_number'] ?? null : null;

        return new self(
            type: $type,
            livemode: $livemode,
            checkoutSessionId: is_string($sessionId) && preg_match('/\Acs_[A-Za-z0-9]+\z/', $sessionId) === 1 ? $sessionId : null,
            referenceNumber: is_string($referenceNumber) && $referenceNumber !== '' ? $referenceNumber : null,
        );
    }

    public function isCheckoutPaid(): bool
    {
        return $this->type === self::CHECKOUT_PAID;
    }
}
