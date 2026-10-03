<?php

declare(strict_types=1);

use App\Actions\CreateStarToken;
use App\Actions\SwitchStarTools;
use App\Enums\ActivityStatus;
use App\Models\ActivityEntry;
use App\Models\Connection;
use App\Models\ConnectionPrompt;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\ToolCallCount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Tests\Support\FakeMcpServer;
use Tests\Support\StarClient;

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

it('refuses a Free user\'s 3,001st call of the week without forwarding it, and records it as the weekly limit', function (): void {
    $server = FakeMcpServer::at()->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);
    ToolCallCount::factory()->for($this->user)->create(['calls' => 2999]);

    $this->client->callTool('wiki__search')->assertOk()->assertJsonPath('result.content.0.text', 'Found it');
    $refused = $this->client->callTool('wiki__search')->assertOk();

    expect($refused->json('result'))->toMatchArray([
        'content' => [[
            'type' => 'text',
            'text' => 'This Nexus account has used its 3,000 free tool calls this week. They reset on Monday, Oct 5 at 12:00 AM Philippine time. Upgrade to Pro for unlimited tool calls: '.route('billing.upgrade'),
        ]],
        'isError' => true,
    ]);
    expect($server->received('tools/call'))->toHaveCount(1);
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
