<?php

declare(strict_types=1);

namespace App\Connectors;

/**
 * How a user registers their own OAuth app for a connector whose server only
 * accepts registered apps.
 */
final readonly class ConnectorApp
{
    /**
     * @param  array<array-key, mixed>|null  $manifest  An app manifest the provider accepts, if it has one.
     */
    public function __construct(
        public string $consoleUrl,
        public string $instructions,
        public ?array $manifest = null,
    ) {}
}
