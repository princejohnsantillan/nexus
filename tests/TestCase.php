<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Every downstream server and authorization server in the suite is
        // faked. A request nothing answers is a test bug, not a real call.
        Http::preventStrayRequests();

        // Passport's signing keys are git-ignored; a fresh checkout makes its own.
        if (! file_exists(storage_path('oauth-private.key'))) {
            Artisan::call('passport:keys');
        }
    }
}
