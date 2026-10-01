<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Connectors that tests add beside the shipped ones.
 */
final class ConnectorFixture
{
    /**
     * A random connector key that no file in `resources/connectors` uses, so
     * a test adding it to a copy of the shipped connectors adds one more,
     * whatever ships.
     */
    public static function unshippedKey(): string
    {
        do {
            $key = 'fixture-'.Str::lower(Str::random(8));
        } while (File::exists(resource_path("connectors/{$key}.json")));

        return $key;
    }
}
