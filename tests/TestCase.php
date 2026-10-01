<?php

declare(strict_types=1);

namespace Tests;

use App\Encryption\SecretCipher;
use App\Outbound\DnsResolver;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeDnsResolver;
use Tests\Support\PassportKeys;

abstract class TestCase extends BaseTestCase
{
    /**
     * Keep every test off the network and independent of the front-end build,
     * give it a fresh master key so it can encrypt credentials, and give
     * Passport a key pair to sign OAuth access tokens with.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->fakeDns();

        $this->withoutVite();

        config(['nexus.encryption.master_key' => 'base64:'.base64_encode(Encrypter::generateKey(SecretCipher::CIPHER))]);

        config(['passport.private_key' => PassportKeys::pair()['private'], 'passport.public_key' => PassportKeys::pair()['public']]);
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
