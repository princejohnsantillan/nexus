<?php

declare(strict_types=1);

use App\Actions\CreateStarToken;
use App\Actions\SwitchStarTools;
use App\Enums\ActivityStatus;
use App\Enums\ConnectionAuthType;
use App\Models\ActivityEntry;
use App\Models\Connection;
use App\Models\ConnectionPrompt;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\ToolCallCount;
use App\Models\User;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Tests\Support\ConnectionOAuthFlow;
use Tests\Support\FakeAuthorizationServer;
use Tests\Support\FakeMcpServer;
use Tests\Support\StarClient;
use Tests\TestCase;

beforeEach(function (): void {
    config(['nexus.plans.free.tool_calls_per_week' => 3000]);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 06:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->wiki = Connection::factory()->for($this->user)->connected()->create(['name' => 'DeepWiki', 'handle' => 'wiki']);
    ConnectionTool::factory()->for($this->wiki)->create(['name' => 'search', 'read_only' => true]);
    $this->star = Star::factory()->for($this->user)->including($this->wiki)->create(['name' => 'Work']);
    $this->client = StarClient::for($this->star)->withToken(resolve(CreateStarToken::class)->handle($this->star, 'Laptop')->plainTextToken);
});

it('counts every call it forwards, through any of the user\'s Stars', function (): void {
    FakeMcpServer::at()->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);
    $home = Star::factory()->for($this->user)->including($this->wiki)->create(['name' => 'Home']);
    $homeClient = StarClient::for($home)->withToken(resolve(CreateStarToken::class)->handle($home, 'Phone')->plainTextToken);

    $this->client->callTool('wiki__search')->assertOk();
    $homeClient->callTool('wiki__search')->assertOk();
    $this->client->callTool('wiki__search')->assertOk();

    $this->assertDatabaseHas('tool_call_counts', ['user_id' => $this->user->id, 'week_starts_on' => '2026-09-28', 'calls' => 3]);
});

it('counts a forwarded call that then fails', function (Closure $responder): void {
    FakeMcpServer::at()->respondTo('tools/call', $responder);

    $this->client->callTool('wiki__search')->assertOk()->assertJsonPath('result.isError', true);

    $this->assertDatabaseHas('tool_call_counts', ['user_id' => $this->user->id, 'calls' => 1]);
})->with([
    'the server is down' => [FakeMcpServer::httpStatus(503)],
    'the tool answers with an error' => [FakeMcpServer::jsonRpcResult(['content' => [['type' => 'text', 'text' => 'No such page']], 'isError' => true])],
]);

it('counts no call it refuses before forwarding, and no prompt fetch', function (): void {
    ConnectionPrompt::factory()->for($this->wiki)->definedAs('{"name":"summarize"}')->create();
    ConnectionTool::factory()->for($this->wiki)->create(['name' => 'delete_page', 'read_only' => false]);
    resolve(SwitchStarTools::class)->handle($this->star, $this->wiki, false, ['delete_page']);
    FakeMcpServer::at()->withPrompts([['name' => 'summarize']]);

    $this->client->callTool('wiki__delete_page')->assertStatus(400);
    $this->client->callTool('wiki__nope')->assertStatus(400);
    $this->client->callTool('wiki__search', '[1]')->assertStatus(400);
    $this->client->speaking('2025-11-25')->send('tools/call', '{"arguments":{}}')->assertStatus(400);
    $this->client->getPrompt('wiki__summarize')->assertOk();

    $this->assertDatabaseEmpty('tool_call_counts');
});

it('counts no call it can\'t send because the Connection can\'t sign in', function (Closure $arrange): void {
    $server = FakeMcpServer::at()->requireOAuth()->withTools([['name' => 'search', 'annotations' => ['readOnlyHint' => true]]])->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);
    $notion = Connection::factory()->for($this->user)->oauth()->create(['name' => 'Notion', 'handle' => 'notion']);
    ConnectionTool::factory()->for($notion)->create(['name' => 'search', 'read_only' => true]);
    $star = Star::factory()->for($this->user)->including($notion)->create(['name' => 'Notes']);
    $client = StarClient::for($star)->withToken(resolve(CreateStarToken::class)->handle($star, 'Laptop')->plainTextToken);
    $this->actingAs($this->user);
    $arrange($this, $notion, $server->authorizationServer());
    $requestsBefore = count($server->requests());

    $response = $client->callTool('notion__search')->assertOk()->assertJsonPath('result.isError', true);

    expect($response->json('result.content.0.text'))->toContain('The Notion Connection needs signing in again')
        ->and(count($server->requests()))->toBe($requestsBefore)
        ->and(ActivityEntry::query()->sole()->status)->toBe(ActivityStatus::NeedsAuth);
    $this->assertDatabaseEmpty('tool_call_counts');
})->with([
    'it never signed in' => [function (TestCase $test, Connection $notion, FakeAuthorizationServer $auth): void {}],
    'its token expired and there is no refresh token' => [function (TestCase $test, Connection $notion, FakeAuthorizationServer $auth): void {
        $auth->withoutRefreshTokens();
        ConnectionOAuthFlow::signIn($test, $notion, $auth);
        $test->travel(2)->hours();
    }],
    'its server won\'t renew the token' => [function (TestCase $test, Connection $notion, FakeAuthorizationServer $auth): void {
        ConnectionOAuthFlow::signIn($test, $notion, $auth);
        $auth->respondTo('token', fn (): PromiseInterface => Http::response(['error' => 'invalid_grant'], 400));
        $test->travel(2)->hours();
    }],
]);

it('counts no call the outbound guard refuses, as when the server\'s name now points into a private network', function (): void {
    $server = FakeMcpServer::at()->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);
    $this->fakeDns(['mcp.example.com' => ['10.0.0.7']]);

    $this->client->callTool('wiki__search')->assertOk()->assertJsonPath('result.isError', true);

    expect($server->requests())->toBe([])
        ->and(ActivityEntry::query()->sole()->status)->toBe(ActivityStatus::Error);
    $this->assertDatabaseEmpty('tool_call_counts');
});

it('counts no call whose request can\'t be built, as with a header value holding a line break', function (): void {
    $server = FakeMcpServer::at()->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);
    $this->wiki->forceFill(['auth_type' => ConnectionAuthType::Header, 'settings' => ['header_name' => 'Authorization']]);
    $this->wiki->secrets->put(['header_value' => "Bearer sk-test\r\nX-Injected: 1"]);
    $this->wiki->save();

    $this->client->callTool('wiki__search')->assertOk()->assertJsonPath('result.isError', true);

    expect($server->requests())->toBe([])
        ->and(ActivityEntry::query()->sole()->status)->toBe(ActivityStatus::NeedsAuth);
    $this->assertDatabaseEmpty('tool_call_counts');
});

it('counts no call whose handshake fails, since the call itself never leaves', function (): void {
    $server = FakeMcpServer::at()->respondTo('initialize', FakeMcpServer::unreachable())->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);

    $this->client->callTool('wiki__search')->assertOk()->assertJsonPath('result.isError', true);

    expect($server->received('tools/call'))->toBe([]);
    $this->assertDatabaseEmpty('tool_call_counts');
});

it('refuses a call that loses the last one of the week as it would have left, without sending it', function (): void {
    ToolCallCount::factory()->for($this->user)->create(['calls' => 2999]);
    $server = FakeMcpServer::at()
        ->beforeAnswering('initialize', fn (): int => ToolCallCount::query()->where('user_id', $this->user->id)->update(['calls' => 3000]))
        ->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);

    $response = $this->client->callTool('wiki__search')->assertOk()->assertJsonPath('result.isError', true);

    expect($response->json('result.content.0.text'))->toStartWith('This Nexus account has used its 3,000 free tool calls this week.')
        ->and($server->received('tools/call'))->toBe([])
        ->and(ActivityEntry::query()->sole()->status)->toBe(ActivityStatus::Limited);
    $this->assertDatabaseHas('tool_call_counts', ['user_id' => $this->user->id, 'calls' => 3000]);
});

it('counts a call whose expired sign-in it renews first', function (): void {
    $server = FakeMcpServer::at()->requireOAuth()->withTools([['name' => 'search', 'annotations' => ['readOnlyHint' => true]]])->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);
    $notion = Connection::factory()->for($this->user)->oauth()->create(['name' => 'Notion', 'handle' => 'notion']);
    ConnectionTool::factory()->for($notion)->create(['name' => 'search', 'read_only' => true]);
    $star = Star::factory()->for($this->user)->including($notion)->create(['name' => 'Notes']);
    $client = StarClient::for($star)->withToken(resolve(CreateStarToken::class)->handle($star, 'Laptop')->plainTextToken);
    $this->actingAs($this->user);
    ConnectionOAuthFlow::signIn($this, $notion, $server->authorizationServer());
    $this->travel(2)->hours();

    $client->callTool('notion__search')->assertOk()->assertJsonPath('result.content.0.text', 'Found it');

    expect($server->authorizationServer()->tokenRequests('refresh_token'))->toHaveCount(1);
    $this->assertDatabaseHas('tool_call_counts', ['user_id' => $this->user->id, 'calls' => 1]);
});

it('refuses a Free user\'s 3,001st call of the week without forwarding it, and records it as the weekly limit', function (): void {
    $server = FakeMcpServer::at()->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);
    ToolCallCount::factory()->for($this->user)->create(['calls' => 2999]);

    $this->client->callTool('wiki__search')->assertOk()->assertJsonPath('result.content.0.text', 'Found it');
    $requestsBefore = count($server->requests());
    $refused = $this->client->callTool('wiki__search')->assertOk();

    expect($refused->json('result'))->toMatchArray([
        'content' => [[
            'type' => 'text',
            'text' => 'This Nexus account has used its 3,000 free tool calls this week. They reset on Monday, Oct 5 at 12:00 AM Philippine time. Upgrade to Pro for unlimited tool calls: '.route('billing.upgrade'),
        ]],
        'isError' => true,
    ]);
    expect($server->received('tools/call'))->toHaveCount(1)
        ->and(count($server->requests()))->toBe($requestsBefore);
    expect(ActivityEntry::query()->orderBy('id')->get()->map->only(['exposed_name', 'connection_id', 'downstream_name', 'status'])->all())->toBe([
        ['exposed_name' => 'wiki__search', 'connection_id' => $this->wiki->id, 'downstream_name' => 'search', 'status' => ActivityStatus::Ok],
        ['exposed_name' => 'wiki__search', 'connection_id' => $this->wiki->id, 'downstream_name' => 'search', 'status' => ActivityStatus::Limited],
    ]);
    $this->assertDatabaseHas('tool_call_counts', ['user_id' => $this->user->id, 'calls' => 3000]);
});

it('lets calls through again from Monday at midnight in Philippine time', function (): void {
    FakeMcpServer::at()->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);
    ToolCallCount::factory()->for($this->user)->create(['week_starts_on' => '2026-09-28', 'calls' => 3000]);

    $this->travelTo(CarbonImmutable::parse('2026-10-04 15:59:59', 'UTC'));
    $sunday = $this->client->callTool('wiki__search')->assertOk();

    $this->travelTo(CarbonImmutable::parse('2026-10-04 16:00:00', 'UTC'));
    $monday = $this->client->callTool('wiki__search')->assertOk();

    expect($sunday->json('result.isError'))->toBeTrue()
        ->and($monday->json('result.content.0.text'))->toBe('Found it');
    expect($this->user->toolCallCounts()->orderBy('week_starts_on')->get()->map->only(['week_starts_on', 'calls'])->all())->toBe([
        ['week_starts_on' => '2026-09-28', 'calls' => 3000],
        ['week_starts_on' => '2026-10-05', 'calls' => 1],
    ]);
});

it('never refuses Pro, and still counts its calls', function (): void {
    FakeMcpServer::at()->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);
    $this->user->forceFill(['pro_until' => now()->addYear()])->save();
    ToolCallCount::factory()->for($this->user)->create(['calls' => 3000]);

    $this->client->callTool('wiki__search')->assertOk()->assertJsonPath('result.content.0.text', 'Found it');

    $this->assertDatabaseHas('tool_call_counts', ['user_id' => $this->user->id, 'calls' => 3001]);
});

it('lifts the limit as soon as the user goes Pro', function (): void {
    FakeMcpServer::at()->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);
    ToolCallCount::factory()->for($this->user)->create(['calls' => 3000]);
    $this->client->callTool('wiki__search')->assertOk()->assertJsonPath('result.isError', true);

    $this->user->forceFill(['pro_until' => now()->addMonth()])->save();

    $this->client->callTool('wiki__search')->assertOk()->assertJsonPath('result.content.0.text', 'Found it');
});

it('limits a user whose Pro ends in the week by the calls they made on Pro', function (): void {
    config(['nexus.plans.free.tool_calls_per_week' => 2]);
    FakeMcpServer::at()->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);
    $this->user->forceFill(['pro_until' => now()->addHour()])->save();
    $this->client->callTool('wiki__search')->assertOk();
    $this->client->callTool('wiki__search')->assertOk();
    $this->client->callTool('wiki__search')->assertOk();

    $this->travel(2)->hours();

    $this->client->callTool('wiki__search')->assertOk()->assertJsonPath('result.isError', true);
    expect(ActivityEntry::query()->latest('id')->first()?->status)->toBe(ActivityStatus::Limited);
});

it('counts no request the per-minute rate limit stopped', function (): void {
    config(['nexus.limits.calls_per_minute' => 1]);
    FakeMcpServer::at()->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);

    $this->client->callTool('wiki__search')->assertOk();
    $this->client->callTool('wiki__search')->assertTooManyRequests();

    $this->assertDatabaseHas('tool_call_counts', ['user_id' => $this->user->id, 'calls' => 1]);
});

it('prunes weeks older than 8, once a day', function (): void {
    foreach (['2026-09-28', '2026-08-03', '2026-07-27'] as $week) {
        ToolCallCount::factory()->for($this->user)->create(['week_starts_on' => $week]);
    }

    $this->artisan('model:prune', ['--model' => [ToolCallCount::class]])->assertSuccessful();

    expect($this->user->toolCallCounts()->orderBy('week_starts_on')->pluck('week_starts_on')->all())->toBe(['2026-08-03', '2026-09-28'])
        ->and(collect(resolve(Schedule::class)->events())->sole(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'model:prune'))->command)
        ->toContain(ToolCallCount::class);
});
