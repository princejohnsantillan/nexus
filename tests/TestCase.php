<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Every downstream server and authorization server in the suite is
        // faked. A request nothing answers is a test bug, not a real call.
        Http::preventStrayRequests();
    }
}
