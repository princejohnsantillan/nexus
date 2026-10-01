<?php

declare(strict_types=1);

use App\Enums\NewToolPolicy;
use App\Enums\StarAccessMode;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();

    $this->actingAs($this->user);
});

it('shows an empty state with a way to create the first Star', function (): void {
    Livewire::test('pages::stars.index')
        ->assertOk()
        ->assertSeeText('No Stars yet')
        ->assertSeeText('Create a Star, choose the Connections it includes')
        ->assertSeeText('Create your first Star');
});

it('lists the user\'s Stars with their Connections, how many tools are on and their access mode', function (): void {
    $wiki = Connection::factory()->for($this->user)->create(['name' => 'DeepWiki']);
    ConnectionTool::factory()->for($wiki)->create(['read_only' => true]);
    ConnectionTool::factory()->for($wiki)->create(['read_only' => false]);
    $docs = Connection::factory()->for($this->user)->create(['name' => 'Docs']);
    ConnectionTool::factory()->for($docs)->create(['read_only' => true]);
    Star::factory()->for($this->user)->including($wiki, $docs)->create(['name' => 'Work', 'description' => 'For the work laptop']);
    Star::factory()->for($this->user)->create(['name' => 'Empty']);

    $this->get(route('stars.index'))
        ->assertOk()
        ->assertSee('<title>Stars · Nexus</title>', escape: false)
        ->assertSeeTextInOrder(['Name', 'Connections', 'Tools on', 'Access'])
        ->assertSeeTextInOrder(['Empty', 'None yet', '0 of 0', 'Bearer token'])
        ->assertSeeTextInOrder(['Work', 'For the work laptop', 'DeepWiki', 'Docs', '2 of 3', 'Bearer token'])
        ->assertSee(route('stars.show', Star::query()->where('name', 'Work')->sole()));
});

it('shows only the user\'s own Stars', function (): void {
    Star::factory()->create(['name' => 'Someone else\'s Star']);

    Livewire::test('pages::stars.index')
        ->assertSeeText('No Stars yet')
        ->assertDontSeeText('Someone else\'s Star');
});

it('creates a Star with the chosen Connections and opens it', function (): void {
    $wiki = Connection::factory()->for($this->user)->create(['name' => 'DeepWiki']);
    Connection::factory()->for($this->user)->create(['name' => 'Left out']);

    Livewire::test('pages::stars.index')
        ->assertSeeText('DeepWiki')
        ->set('name', '  Work  ')
        ->set('description', ' For the work laptop ')
        ->set('connectionIds', [(string) $wiki->id])
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect(route('stars.show', $star = $this->user->stars()->sole()));

    expect($star->only(['name', 'slug', 'description', 'access_mode', 'new_tool_policy', 'signed_url_version']))->toBe([
        'name' => 'Work',
        'slug' => 'work',
        'description' => 'For the work laptop',
        'access_mode' => StarAccessMode::Token,
        'new_tool_policy' => NewToolPolicy::ReadOnly,
        'signed_url_version' => 1,
    ])
        ->and($star->public_id)->toMatch('/^[a-z0-9]{20}$/')
        ->and($star->connections()->pluck('name')->all())->toBe(['DeepWiki']);
    $this->get(route('stars.show', $star))->assertSeeText('Created Work.');
});

it('offers each access mode with a short explanation when creating a Star, tokens first', function (): void {
    Livewire::test('pages::stars.index')
        ->assertSet('accessMode', 'token')
        ->assertSeeTextInOrder([
            'Access mode',
            'Bearer token', 'Clients send a token you create for each of them in a header.',
            'Signed URL', 'One secret URL that works by itself, for clients that only take a URL.',
        ]);
});

it('creates a Star that clients reach with its signed URL', function (): void {
    Livewire::test('pages::stars.index')
        ->set('name', 'Web')
        ->set('accessMode', 'signed_url')
        ->call('create')
        ->assertHasNoErrors();

    $star = $this->user->stars()->sole();

    expect($star->access_mode)->toBe(StarAccessMode::SignedUrl);
    $this->get(route('stars.index'))->assertSeeTextInOrder(['Web', 'Signed URL']);
});

it('refuses an access mode that does not exist', function (): void {
    Livewire::test('pages::stars.index')
        ->set('name', 'Work')
        ->set('accessMode', 'open')
        ->call('create')
        ->assertHasErrors(['accessMode']);

    expect(Star::query()->count())->toBe(0);
});

it('creates a Star without Connections, which can be added later', function (): void {
    Livewire::test('pages::stars.index')
        ->assertSeeText('You can create the Star now and add Connections to it later.')
        ->assertSee(route('connections.add'))
        ->set('name', 'Later')
        ->call('create')
        ->assertHasNoErrors();

    expect($this->user->stars()->sole()->connections()->count())->toBe(0);
});

it('gives each of the user\'s Stars its own slug', function (): void {
    Star::factory()->for($this->user)->create(['name' => 'Work', 'slug' => 'work']);
    Star::factory()->create(['name' => 'Work', 'slug' => 'work']);

    Livewire::test('pages::stars.index')->set('name', 'Work')->call('create')->assertHasNoErrors();
    Livewire::test('pages::stars.index')->set('name', 'WORK!')->call('create')->assertHasNoErrors();
    Livewire::test('pages::stars.index')->set('name', '星')->call('create')->assertHasNoErrors();

    expect($this->user->stars()->orderBy('id')->pluck('slug')->all())->toBe(['work', 'work-2', 'work-3', 'star']);
});

it('requires a name', function (): void {
    Livewire::test('pages::stars.index')
        ->set('name', '   ')
        ->call('create')
        ->assertHasErrors(['name' => 'required']);

    expect($this->user->stars()->count())->toBe(0);
});

it('refuses another user\'s Connection', function (): void {
    $someoneElses = Connection::factory()->create();

    Livewire::test('pages::stars.index')
        ->set('name', 'Work')
        ->set('connectionIds', [(string) $someoneElses->id])
        ->call('create')
        ->assertHasErrors(['connectionIds.0' => 'Choose only your own Connections.']);

    expect(Star::query()->count())->toBe(0);
});

it('stops at the Stars limit with a friendly message', function (): void {
    config(['nexus.limits.stars_per_user' => 2]);
    Star::factory()->for($this->user)->count(2)->create();

    Livewire::test('pages::stars.index')
        ->assertSeeText('Star limit reached')
        ->assertSeeText('You have 2 Stars, the most an account can have. Delete one to create another.')
        ->set('name', 'One too many')
        ->call('create')
        ->assertHasErrors(['limit' => 'You have 2 Stars, the most an account can have. Delete one to create another.']);

    expect($this->user->stars()->count())->toBe(2);
});

it('says "1 Star" when the limit is one', function (): void {
    config(['nexus.limits.stars_per_user' => 1]);
    Star::factory()->for($this->user)->create();

    Livewire::test('pages::stars.index')
        ->assertSeeText('You have 1 Star, the most an account can have. Delete it to create another.');
});

it('escapes the names and descriptions users give their Stars', function (): void {
    Star::factory()->for($this->user)->create(['name' => '<script>alert("name")</script>', 'description' => '<img src=x onerror=alert(1)>']);

    $this->get(route('stars.index'))
        ->assertOk()
        ->assertDontSee('<script>alert("name")</script>', escape: false)
        ->assertDontSee('<img src=x onerror=alert(1)>', escape: false);
});
