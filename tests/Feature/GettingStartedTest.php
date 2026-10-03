<?php

declare(strict_types=1);

use App\Enums\StarAccessMode;
use App\Models\ActivityEntry;
use App\Models\Connection;
use App\Models\Star;
use App\Models\StarOAuthClient;
use App\Models\StarToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();

    $this->actingAs($this->user);
});

it('starts a new user at connecting a server, with every step to do', function (): void {
    Livewire::test('pages::stars.index')
        ->assertSeeText('Getting started')
        ->assertSeeText('0 of 4 done')
        ->assertSeeTextInOrder([
            'Connect a server', '(to do)', 'Add connection',
            'Create a Star', '(to do)', 'Create Star',
            'Set up a client', '(to do)', 'Once you have a Star',
            'First call received', '(to do)', 'No calls yet',
        ])
        ->assertSeeHtml('data-step="connect-server" aria-current="step"')
        ->assertSeeHtml('href="'.route('connections.index').'#add-more"');
});

it('ticks off connecting a server once the user has a Connection, naming their Connections', function (): void {
    Connection::factory()->for($this->user)->count(3)->sequence(['name' => 'Notion'], ['name' => 'GitHub'], ['name' => 'Linear'])->create();

    Livewire::test('pages::stars.index')
        ->assertSeeText('1 of 4 done')
        ->assertSeeTextInOrder(['Connect a server', '(done)', 'GitHub, Linear, Notion', 'Create a Star', '(to do)', 'Create Star'])
        ->assertSeeHtml('data-step="create-star" aria-current="step"');
});

it('ticks off creating a Star once the user has one, and links to the newest Star\'s client setup', function (): void {
    Connection::factory()->for($this->user)->create();
    Star::factory()->for($this->user)->create(['name' => 'Work', 'created_at' => now()->subDay()]);
    $personal = Star::factory()->for($this->user)->create(['name' => 'Personal']);

    Livewire::test('pages::stars.index')
        ->assertSeeText('2 of 4 done')
        ->assertSeeTextInOrder(['Create a Star', '(done)', 'Personal', 'Set up a client', '(to do)', 'Add Personal to a client', 'Open setup'])
        ->assertSeeHtml('data-step="set-up-client" aria-current="step"')
        ->assertSeeHtml('href="'.route('stars.show', $personal).'#setup"');
});

it('ticks off setting up a client once a Star has one, then listens on that Star for the first call', function (StarAccessMode $accessMode, Closure $setUp, string $client): void {
    Connection::factory()->for($this->user)->create();
    $personal = Star::factory()->for($this->user)->withAccessMode($accessMode)->create(['name' => 'Personal', 'created_at' => now()->subDay()]);
    $setUp($personal);
    Star::factory()->for($this->user)->create(['name' => 'Newer']);

    Livewire::test('pages::stars.index')
        ->assertSeeText('3 of 4 done')
        ->assertSeeTextInOrder(['Create a Star', '(done)', 'Personal', 'Set up a client', '(done)', $client, 'First call received', '(to do)', 'Listening on Personal…', 'Open setup'])
        ->assertSeeHtml('data-step="receive-first-call" aria-current="step"')
        ->assertSeeHtml('href="'.route('stars.show', $personal).'#setup"');
})->with([
    'a token' => [StarAccessMode::Token, fn (Star $star): StarToken => StarToken::factory()->for($star)->create(['name' => 'Cursor']), 'Cursor'],
    'a connected app' => [StarAccessMode::OAuth, fn (Star $star): StarOAuthClient => StarOAuthClient::factory()->for($star)->approved()->create(), 'Claude'],
    'its signed URL' => [StarAccessMode::SignedUrl, fn (Star $star): null => null, 'Signed URL'],
]);

it('does not count an app the user has not approved, or one they revoked, as a client', function (): void {
    Connection::factory()->for($this->user)->create();
    $star = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::OAuth)->create(['name' => 'Personal']);
    StarOAuthClient::factory()->for($star)->create();
    StarOAuthClient::factory()->for($star)->approved()->create(['client_id' => Client::factory()->asPublic()->create(['name' => 'Revoked', 'revoked' => true])->id]);

    Livewire::test('pages::stars.index')
        ->assertSeeText('2 of 4 done')
        ->assertSeeTextInOrder(['Set up a client', '(to do)', 'Add Personal to a client'])
        ->assertSeeHtml('data-step="set-up-client" aria-current="step"');
});

it('ticks off the first call once a Star has been called', function (StarAccessMode $accessMode, Closure $call): void {
    $star = Star::factory()->for($this->user)->withAccessMode($accessMode)->create(['name' => 'Personal']);
    $call($star);

    Livewire::test('pages::stars.index')
        ->assertSeeText('3 of 4 done')
        ->assertSeeTextInOrder(['First call received', '(done)', 'Personal'])
        ->assertSeeHtml('data-step="connect-server" aria-current="step"');
})->with([
    'in Activity' => [StarAccessMode::SignedUrl, fn (Star $star): ActivityEntry => ActivityEntry::factory()->for($star->user)->for($star)->create()],
    'with a token' => [StarAccessMode::Token, fn (Star $star): StarToken => StarToken::factory()->for($star)->create(['last_used_at' => now()])],
    'by a connected app' => [StarAccessMode::OAuth, fn (Star $star): StarOAuthClient => StarOAuthClient::factory()->for($star)->approved()->create(['last_used_at' => now()])],
]);

it('never ticks a step with another user\'s data', function (): void {
    Star::factory()->for($this->user)->create(['name' => 'Mine']);
    $someoneElse = User::factory()->create();
    $connection = Connection::factory()->for($someoneElse)->create(['name' => 'Their Connection']);
    $theirs = Star::factory()->for($someoneElse)->including($connection)->create(['name' => 'Theirs']);
    StarToken::factory()->for($theirs)->create(['name' => 'Their token', 'last_used_at' => now()]);
    StarOAuthClient::factory()->for($theirs)->approved()->create(['last_used_at' => now()]);
    ActivityEntry::factory()->through($theirs, $connection)->create();

    Livewire::test('pages::stars.index')
        ->assertSeeText('1 of 4 done')
        ->assertSeeTextInOrder(['Connect a server', '(to do)', 'Create a Star', '(done)', 'Mine', 'Set up a client', '(to do)', 'First call received', '(to do)'])
        ->assertDontSeeText('Their Connection')
        ->assertDontSeeText('Their token');
});

it('dismisses the checklist for good, on every device', function (): void {
    $this->travelTo('2026-10-03 09:00:00');

    Livewire::test('pages::stars.index')
        ->call('dismissGettingStarted')
        ->assertDontSeeHtml('data-getting-started');

    expect($this->user->fresh()?->getting_started_closed_at?->toDateTimeString())->toBe('2026-10-03 09:00:00');
    $this->get(route('stars.index'))->assertDontSee('data-getting-started', escape: false);
});

it('closes for good once every step is done, even if a step is undone later', function (): void {
    $this->travelTo('2026-10-03 09:00:00');
    $connection = Connection::factory()->for($this->user)->create();
    $star = Star::factory()->for($this->user)->including($connection)->create();
    StarToken::factory()->for($star)->create(['last_used_at' => now()]);

    Livewire::test('pages::stars.index')->assertDontSeeHtml('data-getting-started');

    expect($this->user->fresh()?->getting_started_closed_at?->toDateTimeString())->toBe('2026-10-03 09:00:00');
    $connection->delete();
    Livewire::test('pages::stars.index')->assertDontSeeHtml('data-getting-started');
});

it('works the checklist out in two queries however many Stars the user has, and in none once it is closed', function (): void {
    Connection::factory()->for($this->user)->create();

    foreach (Star::factory()->for($this->user)->count(3)->create() as $star) {
        StarToken::factory()->for($star)->create();
    }

    $this->get(route('stars.index'))->assertSee('data-getting-started', escape: false);
    DB::enableQueryLog();
    $this->get(route('stars.index'))->assertSee('data-getting-started', escape: false);
    $whileOpen = count(DB::getQueryLog());
    $this->user->forceFill(['getting_started_closed_at' => now()])->save();
    DB::flushQueryLog();
    $this->get(route('stars.index'))->assertDontSee('data-getting-started', escape: false);
    $onceClosed = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($whileOpen - $onceClosed)->toBe(2);
});
