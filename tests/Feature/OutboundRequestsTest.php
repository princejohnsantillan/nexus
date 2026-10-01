<?php

namespace Tests\Feature;

use App\Security\OutboundGuard;
use App\Security\OutboundRequestBlocked;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutboundRequestsTest extends TestCase
{
    public function test_http_client_requests_to_private_networks_are_blocked(): void
    {
        $this->app->instance(OutboundGuard::class, new OutboundGuard(resolver: fn (): array => ['169.254.169.254']));
        Http::fake();

        $this->expectException(OutboundRequestBlocked::class);

        Http::get('https://rebinding.example.com/latest/meta-data');
    }

    public function test_http_client_requests_to_public_hosts_go_through(): void
    {
        $this->app->instance(OutboundGuard::class, new OutboundGuard(resolver: fn (): array => ['93.184.216.34']));
        Http::fake(['https://mcp.example.com/*' => Http::response('ok')]);

        $this->assertSame('ok', Http::get('https://mcp.example.com/mcp')->body());
    }

    public function test_redirects_are_not_followed(): void
    {
        $this->app->instance(OutboundGuard::class, new OutboundGuard(resolver: fn (): array => ['93.184.216.34']));
        Http::fake(['https://mcp.example.com/*' => Http::response('', 302, ['Location' => 'http://10.0.0.1/admin'])]);

        $this->assertSame(302, Http::get('https://mcp.example.com/mcp')->status());
        Http::assertSentCount(1);
    }
}
