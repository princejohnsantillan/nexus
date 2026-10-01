<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Keep every test off the network and independent of the front-end build.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->withoutVite();
    }
}
