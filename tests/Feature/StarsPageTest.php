<?php

declare(strict_types=1);

use App\Enums\ActivityStatus;
use App\Enums\NewToolPolicy;
use App\Enums\StarAccessMode;
use App\Models\ActivityEntry;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();

    $this->actingAs($this->user);
});

it('shows an empty state with a way to create the first Star', function (): void {
    config(['nexus.limits.stars_per_user' => 10]);

    Livewire::test('pages::stars.index')
        ->assertOk()
        ->assertSeeText('0 / 10 Stars')
        ->assertSeeText('No Stars yet')
        ->assertSeeText('Create a Star, choose the Connections it includes')
        ->assertSeeText('Create your first Star');
});

it('shows each of the user\'s Stars as a card with its Connections, how many tools are on and its access mode', function (): void {
    $wiki = Connection::factory()->for($this->user)->create(['name' => 'DeepWiki']);
    ConnectionTool::factory()->for($wiki)->create(['read_only' => true]);
    ConnectionTool::factory()->for($wiki)->create(['read_only' => false]);
    $docs = Connection::factory()->for($this->user)->create(['name' => 'Docs']);
    ConnectionTool::factory()->for($docs)->create(['read_only' => true]);
    $work = Star::factory()->for($this->user)->including($wiki, $docs)->create(['name' => 'Work', 'description' => 'For the work laptop']);
    Star::factory()->for($this->user)->withAccessMode(StarAccessMode::OAuth)->create(['name' => 'Empty']);
    $more = Connection::factory()->for($this->user)->count(2)->sequence(['name' => 'Linear'], ['name' => 'Notion'])->create();
    Star::factory()->for($this->user)->including($wiki, $docs, ...$more)->create(['name' => 'Everything']);

    $this->get(route('stars.index'))
        ->assertOk()
        ->assertSee('<title>Stars · Nexus</title>', escape: false)
        ->assertSeeTextInOrder(['Empty', 'No Connections yet', '0 of 0 tools on', 'OAuth'])
        ->assertSeeTextInOrder(['Everything', '+1', 'DeepWiki · Docs · Linear · Notion'])
        ->assertSeeTextInOrder(['Work', 'For the work laptop', 'DeepWiki · Docs', '2 of 3 tools on', 'Bearer token'])
        ->assertSee(route('stars.show', $work));
});

it('offers to copy each Star\'s endpoint URL and to see its activity', function (): void {
    $work = Star::factory()->for($this->user)->create(['name' => 'Work']);
    $web = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::SignedUrl)->create(['name' => 'Web']);

    $this->get(route('stars.index'))
        ->assertOk()
        ->assertSeeText('Copy endpoint URL')
        ->assertSee('data-url="'.e($work->endpointUrl()).'"', escape: false)
        ->assertSee('data-url="'.e($web->signedUrl()).'"', escape: false)
        ->assertSeeText('View activity')
        ->assertSee(route('activity.index', ['star' => $work->public_id]))
        ->assertSee(route('activity.index', ['star' => $web->public_id]));
});

it('shows when each Star was last called, from its own Activity', function (): void {
    $this->freezeTime();
    $connection = Connection::factory()->for($this->user)->create();
    $work = Star::factory()->for($this->user)->including($connection)->create(['name' => 'Work']);
    $home = Star::factory()->for($this->user)->including($connection)->create(['name' => 'Home']);
    Star::factory()->for($this->user)->create(['name' => 'Quiet']);
    ActivityEntry::factory()->through($work, $connection)->create(['created_at' => now()->subHour()]);
    ActivityEntry::factory()->through($work, $connection)->withStatus(ActivityStatus::Error)->create(['created_at' => now()->subMinutes(3)]);
    ActivityEntry::factory()->through($home, $connection)->create(['created_at' => now()->subDays(2)]);
    $someoneElses = Star::factory()->create(['name' => 'Theirs']);
    ActivityEntry::factory()->through($someoneElses, Connection::factory()->for($someoneElses->user)->create())->create(['created_at' => now()]);

    $this->get(route('stars.index'))
        ->assertOk()
        ->assertSeeTextInOrder(['Home', 'Last call 2 days ago', 'Quiet', 'Waiting for its first call…', 'Work', 'Last call 3 minutes ago'])
        ->assertDontSeeText('Theirs');
});

it('draws a sparkline of a Star\'s calls over the last two weeks, only when it has some', function (): void {
    $this->freezeTime();
    $connection = Connection::factory()->for($this->user)->create();
    $busy = Star::factory()->for($this->user)->including($connection)->create(['name' => 'Busy']);
    $lapsed = Star::factory()->for($this->user)->including($connection)->create(['name' => 'Lapsed']);
    ActivityEntry::factory()->through($busy, $connection)->count(2)->create(['created_at' => now()->subMinutes(10)]);
    ActivityEntry::factory()->through($busy, $connection)->create(['created_at' => now()->subDays(13)]);
    ActivityEntry::factory()->through($busy, $connection)->create(['created_at' => now()->subDays(15)]);
    ActivityEntry::factory()->through($lapsed, $connection)->create(['created_at' => now()->subDays(20)]);

    $response = $this->get(route('stars.index'))->assertOk();

    $response->assertSeeTextInOrder(['Busy', 'Last call 10 minutes ago', '3 calls in the last 14 days', 'Lapsed', 'Last call 2 weeks ago'])
        ->assertDontSeeText('1 call in the last 14 days');
    expect(substr_count($response->getContent(), 'data-sparkline'))->toBe(1);
});

it('reads every Star\'s calls from Activity in one query, and counts the Stars once', function (): void {
    $this->freezeTime();
    $connection = Connection::factory()->for($this->user)->create();
    $stars = Star::factory()->for($this->user)->including($connection)->count(3)->create();

    foreach ($stars as $star) {
        ActivityEntry::factory()->through($star, $connection)->create(['created_at' => now()->subMinute()]);
    }

    DB::enableQueryLog();
    $this->get(route('stars.index'))->assertOk()->assertSeeText('Last call 1 minute ago');
    DB::disableQueryLog();

    $queries = array_column(DB::getQueryLog(), 'query');
    expect(array_filter($queries, fn (string $query): bool => str_contains($query, 'activity_entries')))->toHaveCount(1)
        ->and(array_filter($queries, fn (string $query): bool => str_starts_with($query, 'select count(*) as "aggregate" from "stars"')))->toHaveCount(1);
});

it('counts the user\'s Stars against the limit next to Create Star', function (): void {
    config(['nexus.limits.stars_per_user' => 5]);
    Star::factory()->for($this->user)->count(2)->create();
    Star::factory()->create();

    $page = Livewire::test('pages::stars.index')
        ->assertSeeTextInOrder(['2 / 5 Stars', 'Create Star'])
        ->assertDontSeeText('Star limit reached');

    expect(createStarIsDisabled($page->html()))->toBeFalse();
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
            'OAuth', 'Clients send you to Nexus to sign in and approve them, so there is no secret to copy.',
        ]);
});

it('creates a Star that clients sign in to with OAuth', function (): void {
    Livewire::test('pages::stars.index')
        ->set('name', 'Phone')
        ->set('accessMode', 'oauth')
        ->call('create')
        ->assertHasNoErrors();

    $star = $this->user->stars()->sole();

    expect($star->access_mode)->toBe(StarAccessMode::OAuth);
    $this->get(route('stars.index'))->assertSeeTextInOrder(['Phone', 'OAuth']);
    $this->getJson(route('mcp.oauth.protected-resource', $star))->assertOk();
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

    $page = Livewire::test('pages::stars.index')
        ->assertSeeTextInOrder(['2 / 2 Stars', 'Create Star'])
        ->assertSeeText('Star limit reached')
        ->assertSeeText('You have 2 Stars, the most an account can have. Delete one to create another.');

    expect(createStarIsDisabled($page->html()))->toBeTrue();

    $page->set('name', 'One too many')
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

/**
 * Whether the page's Create Star button (the one in the header) is disabled.
 */
function createStarIsDisabled(string $html): bool
{
    return preg_match('/<button[^>]*\sdisabled="disabled"[^>]*>(?:(?!<\/button>).)*Create Star/s', $html) === 1;
}
