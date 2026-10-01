<?php

declare(strict_types=1);

namespace Tests;

use App\Outbound\DnsResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeDnsResolver;

abstract class TestCase extends BaseTestCase
{
    /**
     * Keep every test off the network and independent of the front-end build.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->fakeDns();

        $this->withoutVite();
    }

    /**
     * Answer the outbound guard's DNS lookups, which it makes for faked
     * requests too. Every host resolves to a public address unless listed.
     *
     * @param  array<string, list<string>>  $records  Addresses per host; an empty list makes the host unresolvable.
     */
    protected function fakeDns(array $records = []): void
    {
        $this->app->instance(DnsResolver::class, new FakeDnsResolver($records));
    }
}
