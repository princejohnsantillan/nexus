<?php

declare(strict_types=1);

use App\Models\Star;
use App\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->star = Star::factory()->for($this->user)->create();

    $this->actingAs($this->user);
});

afterEach(function (): void {
    unset($_SERVER['LARAVEL_CLOUD']);
});

/**
 * Open the Star's page the way Laravel Cloud's edge passes on a client's
 * HTTPS request: over HTTP, saying in forwarded headers how it arrived.
 */
function openStarThroughTheEdge(Star $star): TestResponse
{
    return test()->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'])
        ->get('http://nexus.example.com/stars/'.$star->public_id);
}

it('trusts Laravel Cloud\'s edge, so a Star\'s endpoint is the HTTPS address clients use', function (): void {
    $_SERVER['LARAVEL_CLOUD'] = '1';

    openStarThroughTheEdge($this->star)->assertOk()->assertSeeHtml('data-star-endpoint-url>https://nexus.example.com/mcp/'.$this->star->public_id.'</span>');
});

it('ignores forwarded headers anywhere but Laravel Cloud', function (): void {
    openStarThroughTheEdge($this->star)->assertOk()->assertSeeHtml('data-star-endpoint-url>http://nexus.example.com/mcp/'.$this->star->public_id.'</span>');
});
