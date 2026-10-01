<?php

declare(strict_types=1);

namespace App\Stars;

use App\Concerns\KeepsSecretsInMemory;
use App\Models\StarToken;
use SensitiveParameter;

/**
 * A Star token just created, with its plain text: the only time Nexus has
 * it, to show the user once.
 */
final readonly class NewStarToken
{
    use KeepsSecretsInMemory;

    public function __construct(
        public StarToken $token,
        #[SensitiveParameter] public string $plainTextToken,
    ) {}
}
