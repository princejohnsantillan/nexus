<?php

declare(strict_types=1);

use App\Enums\ActivityKind;
use App\Enums\ActivityStatus;
use App\Models\ActivityEntry;
use App\Models\Connection;
use App\Models\Star;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();

    $this->actingAs($this->user);
});

it('shows an empty state and how long activity is kept when there is none', function (): void {
    $this->get(route('activity.index'))
        ->assertSee('<title>Activity · Nexus</title>', escape: false)
        ->assertSeeText('Nexus keeps each entry for 30 days, then removes it.')
        ->assertSeeText('No activity yet')
        ->assertSeeText('Arguments and results are never stored.');
});

it('says how long activity is kept when the retention is configured', function (int $days, string $note): void {
    config(['nexus.activity.retention_days' => $days]);

    Livewire::test('pages::activity.index')->assertSeeText($note);
})->with([
    'one day' => [1, 'Nexus keeps each entry for 1 day, then removes it.'],
    'a week' => [7, 'Nexus keeps each entry for 7 days, then removes it.'],
]);

it('lists the user\'s calls newest first with their Star, Connection, name, kind, status, duration and how the client authenticated', function (): void {
    $this->travelTo(now()->startOfDay());
    $wiki = Connection::factory()->for($this->user)->create(['name' => 'DeepWiki', 'handle' => 'wiki']);
    $star = Star::factory()->for($this->user)->including($wiki)->create(['name' => 'Work']);
    ActivityEntry::factory()->through($star, $wiki, 'ask_question')->withStatus(ActivityStatus::Timeout)
        ->create(['duration_ms' => 55012, 'client_name' => 'Laptop', 'created_at' => now()->subMinutes(5)]);
    ActivityEntry::factory()->through($star, $wiki, 'read_wiki_structure')
        ->create(['duration_ms' => 412, 'client_name' => 'Desktop', 'created_at' => now()->subMinute()]);

    $this->get(route('activity.index'))
        ->assertSeeTextInOrder(['Time', 'Star', 'Connection', 'Name', 'Kind', 'Status', 'Duration', 'Via'])
        ->assertSeeTextInOrder([
            '1 minute ago', 'Work', 'DeepWiki', 'wiki__read_wiki_structure', 'Tool', 'OK', '412 ms', 'Bearer token', 'Desktop',
            '5 minutes ago', 'Work', 'DeepWiki', 'wiki__ask_question', 'Tool', 'Timed out', '55,012 ms', 'Bearer token', 'Laptop',
        ])
        ->assertSee(route('stars.show', $star))
        ->assertSee(route('connections.show', $wiki))
        ->assertDontSeeText('No activity yet');
});

it('shows only the user\'s own activity, and only their own Stars and Connections to filter by', function (): void {
    $wiki = Connection::factory()->for($this->user)->create(['name' => 'DeepWiki', 'handle' => 'wiki']);
    $star = Star::factory()->for($this->user)->create(['name' => 'Work']);
    ActivityEntry::factory()->through($star, $wiki, 'search')->create();
    $theirConnection = Connection::factory()->create(['name' => 'Their Linear', 'handle' => 'theirs']);
    $theirStar = Star::factory()->for($theirConnection->user)->create(['name' => 'Their Star']);
    ActivityEntry::factory()->through($theirStar, $theirConnection, 'secret_tool')->create();

    Livewire::test('pages::activity.index')
        ->assertSeeText('wiki__search')
        ->assertDontSeeText('theirs__secret_tool')
        ->assertDontSeeText('Their Star')
        ->assertDontSeeText('Their Linear');
});

it('keeps calls whose Star and Connection were since deleted, and shows them as deleted', function (): void {
    $wiki = Connection::factory()->for($this->user)->create(['name' => 'DeepWiki', 'handle' => 'wiki']);
    $star = Star::factory()->for($this->user)->including($wiki)->create(['name' => 'Work']);
    $entry = ActivityEntry::factory()->through($star, $wiki, 'search')->create();

    $star->delete();
    $wiki->delete();

    $this->assertModelExists($entry);
    $this->get(route('activity.index'))
        ->assertSeeTextInOrder(['Deleted Star', 'Deleted Connection', 'wiki__search', 'OK'])
        ->assertDontSeeText('DeepWiki');
});

it('shows calls that named none of the Star\'s tools without a Connection', function (): void {
    $star = Star::factory()->for($this->user)->create(['name' => 'Work']);
    ActivityEntry::factory()->withStatus(ActivityStatus::Denied)->create([
        'user_id' => $this->user->id, 'star_id' => $star->id, 'exposed_name' => 'nope__search', 'downstream_name' => null,
    ]);
    ActivityEntry::factory()->withStatus(ActivityStatus::Denied)->create([
        'user_id' => $this->user->id, 'star_id' => $star->id, 'exposed_name' => null, 'downstream_name' => null,
    ]);

    $this->get(route('activity.index'))
        ->assertSeeTextInOrder(['Work', 'None', 'No name', 'Denied'])
        ->assertSeeTextInOrder(['Work', 'None', 'nope__search', 'Denied'])
        ->assertDontSeeText('Deleted Connection');
});

it('escapes the names clients send', function (): void {
    $star = Star::factory()->for($this->user)->create();
    ActivityEntry::factory()->withStatus(ActivityStatus::Denied)->create([
        'user_id' => $this->user->id, 'star_id' => $star->id, 'exposed_name' => '<script>alert(1)</script>', 'downstream_name' => null,
    ]);

    $this->get(route('activity.index'))
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', escape: false)
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

describe('filters', function (): void {
    beforeEach(function (): void {
        $wiki = Connection::factory()->for($this->user)->create(['name' => 'DeepWiki', 'handle' => 'wiki']);
        $docs = Connection::factory()->for($this->user)->create(['name' => 'Docs', 'handle' => 'docs']);
        $work = Star::factory()->for($this->user)->create(['name' => 'Work']);
        $home = Star::factory()->for($this->user)->create(['name' => 'Home']);

        ActivityEntry::factory()->through($work, $wiki, 'search')->create();
        ActivityEntry::factory()->through($home, $docs, 'fetch')->withStatus(ActivityStatus::Error)->create();
        ActivityEntry::factory()->through($work, $docs, 'summarize')->create(['kind' => ActivityKind::Prompt]);
        ActivityEntry::factory()->through($home, $wiki, 'ask')->withStatus(ActivityStatus::Timeout)->create();
    });

    it('shows only the calls that match', function (Closure $filters, array $shown): void {
        $page = Livewire::test('pages::activity.index')->set($filters());

        foreach (['wiki__search', 'docs__fetch', 'docs__summarize', 'wiki__ask'] as $name) {
            in_array($name, $shown, true) ? $page->assertSeeText($name) : $page->assertDontSeeText($name);
        }
    })->with([
        'by Star' => [fn (): array => ['starFilter' => Star::query()->where('name', 'Work')->value('public_id')], ['wiki__search', 'docs__summarize']],
        'by Connection' => [fn (): array => ['connectionFilter' => (string) Connection::query()->where('handle', 'docs')->value('id')], ['docs__fetch', 'docs__summarize']],
        'by status' => [fn (): array => ['statusFilter' => 'error'], ['docs__fetch']],
        'by kind' => [fn (): array => ['kindFilter' => 'prompt'], ['docs__summarize']],
        'by Star and Connection' => [fn (): array => [
            'starFilter' => Star::query()->where('name', 'Home')->value('public_id'),
            'connectionFilter' => (string) Connection::query()->where('handle', 'wiki')->value('id'),
        ], ['wiki__ask']],
    ]);

    it('reads the filters from the address bar', function (): void {
        $work = Star::query()->where('name', 'Work')->sole();

        $this->get(route('activity.index', ['star' => $work->public_id, 'kind' => 'tool']))
            ->assertSeeText('wiki__search')
            ->assertDontSeeText('docs__summarize')
            ->assertDontSeeText('docs__fetch')
            ->assertDontSeeText('wiki__ask');
    });

    it('ignores filters that name another user\'s Star or Connection, or no status or kind', function (): void {
        $theirs = ActivityEntry::factory()->through(Star::factory()->create(), Connection::factory()->create(), 'secret_tool')->create();

        Livewire::withQueryParams([
            'star' => $theirs->star?->public_id,
            'connection' => (string) $theirs->connection_id,
            'status' => 'bogus',
            'kind' => 'nope',
        ])->test('pages::activity.index')
            ->assertSet('starFilter', '')
            ->assertSet('connectionFilter', '')
            ->assertSet('statusFilter', '')
            ->assertSet('kindFilter', '')
            ->assertSeeText(['wiki__search', 'docs__fetch', 'docs__summarize', 'wiki__ask'])
            ->assertDontSeeText('secret_tool');
    });

    it('says when no calls match, with a way to clear the filters', function (): void {
        Livewire::test('pages::activity.index')
            ->set('statusFilter', 'needs_auth')
            ->assertSeeText('No matching activity')
            ->assertDontSeeText('wiki__search')
            ->call('clearFilters')
            ->assertSet('statusFilter', '')
            ->assertSeeText(['wiki__search', 'docs__fetch', 'docs__summarize', 'wiki__ask'])
            ->assertDontSeeText('No matching activity');
    });
});

describe('pages', function (): void {
    beforeEach(function (): void {
        $this->travelTo(now()->startOfDay());
        $wiki = Connection::factory()->for($this->user)->create(['handle' => 'wiki']);
        $star = Star::factory()->for($this->user)->create();

        ActivityEntry::factory()->through($star, $wiki)->count(30)->sequence(fn (Sequence $sequence): array => [
            'exposed_name' => sprintf('wiki__call_%02d', $sequence->index + 1),
            'created_at' => now()->subMinutes(30 - $sequence->index),
        ])->create();
    });

    it('pages through the activity 25 calls at a time, newest first', function (): void {
        Livewire::test('pages::activity.index')
            ->assertSeeTextInOrder(['wiki__call_30', 'wiki__call_29', 'wiki__call_06'])
            ->assertDontSeeText('wiki__call_05')
            ->call('nextPage')
            ->assertSeeTextInOrder(['wiki__call_05', 'wiki__call_01'])
            ->assertDontSeeText('wiki__call_06');
    });

    it('goes back to the first page when a filter changes', function (): void {
        Livewire::test('pages::activity.index')
            ->call('nextPage')
            ->set('statusFilter', 'ok')
            ->assertSeeText('wiki__call_30')
            ->assertDontSeeText('wiki__call_05');
    });

    it('shows the last page for a page past the end', function (): void {
        Livewire::withQueryParams(['page' => 9])->test('pages::activity.index')
            ->assertSeeTextInOrder(['wiki__call_05', 'wiki__call_01'])
            ->assertDontSeeText('No matching activity');
    });
});
