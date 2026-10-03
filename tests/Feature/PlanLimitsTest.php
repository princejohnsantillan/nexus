<?php

declare(strict_types=1);

use App\Actions\CreateStarToken;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\FakeMcpServer;
use Tests\Support\StarClient;

beforeEach(function (): void {
    config(['nexus.plans.free.stars' => 2, 'nexus.plans.free.connections' => 10]);
});

dataset('ways to add a Connection', [
    'from the gallery with a token' => [function (): Testable {
        FakeMcpServer::at('https://api.githubcopilot.com/mcp/')->withTools([['name' => 'get_me']]);

        return Livewire::test('pages::connections.index')
            ->call('startConnecting', 'github')
            ->set('method', 'token')
            ->set('token', 'github_pat_good')
            ->call('connect');
    }],
    'from the gallery with OAuth' => [fn (): Testable => Livewire::test('pages::connections.index')
        ->call('startConnecting', 'notion')
        ->set('method', 'oauth')
        ->call('connect')],
    'as a custom server' => [function (): Testable {
        FakeMcpServer::at()->withTools([['name' => 'ask_question']]);

        return Livewire::test('pages::connections.add-custom')
            ->set('name', 'DeepWiki')
            ->set('handle', 'deepwiki')
            ->set('url', FakeMcpServer::DEFAULT_URL)
            ->call('save');
    }],
    'from a Star' => [function (): Testable {
        FakeMcpServer::at('https://api.githubcopilot.com/mcp/')->withTools([['name' => 'get_me']]);
        $star = Star::factory()->for(auth()->user())->create();

        return Livewire::withQueryParams(['star' => $star->public_id])->test('pages::connections.index')
            ->call('startConnecting', 'github')
            ->set('method', 'token')
            ->set('token', 'github_pat_good')
            ->call('connect');
    }],
]);

it('lets a Free user create 2 Stars and refuses a third', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::stars.index')->set('name', 'One')->call('create')->assertHasNoErrors();
    Livewire::test('pages::stars.index')->set('name', 'Two')->call('create')->assertHasNoErrors();
    Livewire::test('pages::stars.index')->set('name', 'Three')->call('create')
        ->assertHasErrors(['limit' => 'Free includes 2 Stars. Go Pro for more, or delete one you no longer use.']);

    expect($user->stars()->pluck('name')->all())->toBe(['One', 'Two']);
});

it('lets a Pro user create Stars past the Free limit', function (): void {
    $user = User::factory()->pro()->create();
    Star::factory()->for($user)->count(5)->create();
    $this->actingAs($user);

    Livewire::test('pages::stars.index')
        ->assertDontSeeText('Star limit reached')
        ->set('name', 'Sixth')
        ->call('create')
        ->assertHasNoErrors();

    expect($user->stars()->count())->toBe(6);
});

it('limits a Free user whose Pro has ended to the Free Stars', function (): void {
    $user = User::factory()->proEnded()->create();
    Star::factory()->for($user)->count(2)->create();
    $this->actingAs($user);

    Livewire::test('pages::stars.index')->set('name', 'Third')->call('create')
        ->assertHasErrors(['limit' => 'Free includes 2 Stars. Go Pro for more, or delete one you no longer use.']);

    expect($user->stars()->count())->toBe(2);
});

it('refuses a Free user\'s 11th Connection', function (Closure $add): void {
    $user = User::factory()->create();
    Connection::factory()->for($user)->count(10)->create();
    $this->actingAs($user);

    $add()->assertHasErrors(['limit' => 'Free includes 10 Connections. Go Pro for more, or delete one you no longer use.']);

    expect($user->connections()->count())->toBe(10);
})->with('ways to add a Connection');

it('lets a Free user add a 10th Connection', function (Closure $add): void {
    $user = User::factory()->create();
    Connection::factory()->for($user)->count(9)->create();
    $this->actingAs($user);

    $add()->assertHasNoErrors();

    expect($user->connections()->count())->toBe(10);
})->with('ways to add a Connection');

it('lets a Pro user add Connections past the Free limit', function (Closure $add): void {
    $user = User::factory()->pro()->create();
    Connection::factory()->for($user)->count(10)->create();
    $this->actingAs($user);

    $add()->assertHasNoErrors();

    expect($user->connections()->count())->toBe(11);
})->with('ways to add a Connection');

it('keeps every Star and Connection of a Free user over the limits working', function (): void {
    $user = User::factory()->proEnded()->create();
    Connection::factory()->for($user)->count(11)->create();
    $wiki = Connection::factory()->for($user)->connected()->create(['name' => 'DeepWiki', 'handle' => 'wiki']);
    ConnectionTool::factory()->for($wiki)->create(['name' => 'search', 'read_only' => true]);
    Star::factory()->for($user)->count(2)->create();
    $third = Star::factory()->for($user)->including($wiki)->create(['name' => 'Third']);
    FakeMcpServer::at()->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);
    $this->actingAs($user);

    Livewire::test('pages::stars.index')
        ->assertSeeText('3 / 2 Stars')
        ->assertSeeText('Third');
    Livewire::test('pages::connections.index')
        ->assertSeeText('Connections used: 12 / 10')
        ->assertSeeText('DeepWiki');

    $token = resolve(CreateStarToken::class)->handle($third, 'Laptop')->plainTextToken;

    StarClient::for($third)->withToken($token)->callTool('wiki__search')
        ->assertOk()
        ->assertJsonPath('result.content.0.text', 'Found it');
});

it('counts Stars and Connections against the limit on Free, and just counts them on Pro', function (): void {
    $free = User::factory()->create();
    Star::factory()->for($free)->count(2)->create();
    Connection::factory()->for($free)->count(7)->create();
    $pro = User::factory()->pro()->create();
    Star::factory()->for($pro)->count(5)->create();
    Connection::factory()->for($pro)->count(14)->create();

    $this->actingAs($free);
    Livewire::test('pages::stars.index')->assertSeeText('2 / 2 Stars');
    Livewire::test('pages::connections.index')->assertSeeText('Connections used: 7 / 10');

    $this->actingAs($pro);
    Livewire::test('pages::stars.index')->assertSeeText('5 Stars')->assertDontSeeText('5 /');
    Livewire::test('pages::connections.index')->assertSeeText('14 Connections')->assertDontSeeText('14 /');
});
