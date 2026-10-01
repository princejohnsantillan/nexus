<?php

declare(strict_types=1);

use Tests\Support\BannedFunctions;

arch()->preset()->laravel();

arch()->preset()->security();

arch('no debug calls')
    ->expect(BannedFunctions::DEBUG)
    ->not->toBeUsed();

arch('strict types')
    ->expect(['App', 'Database'])
    ->toUseStrictTypes();
