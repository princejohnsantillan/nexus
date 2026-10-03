<?php

declare(strict_types=1);

use App\Actions\SwitchStarPrompts;
use App\Actions\SwitchStarTools;
use App\Enums\ActivityKind;
use App\Enums\ActivityStatus;
use App\Enums\StarAccessMode;
use App\Models\ActivityEntry;
use App\Models\Connection;
use App\Models\ConnectionPrompt;
use App\Models\ConnectionTool;
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
        ->assertSeeText('Every tool call and prompt fetch through your Stars, kept for 30 days.')
        ->assertSeeText('No activity yet')
        ->assertSeeText('Arguments and results are never stored.');
});

it('says how long activity is kept when the retention is configured', function (int $days, string $note): void {
    config(['nexus.activity.retention_days' => $days]);

    Livewire::test('pages::activity.index')->assertSeeText($note);
})->with([
    'one day' => [1, 'kept for 1 day.'],
    'a week' => [7, 'kept for 7 days.'],
]);

it('lists the user\'s calls newest first as a log: status, time, name, Star, Connection, client, what went wrong and duration', function (): void {
    $this->travelTo('2026-10-03 09:45:00');
    $wiki = Connection::factory()->for($this->user)->create(['name' => 'DeepWiki', 'handle' => 'wiki']);
    $star = Star::factory()->for($this->user)->including($wiki)->create(['name' => 'Work']);
    ActivityEntry::factory()->through($star, $wiki, 'ask_question')->withStatus(ActivityStatus::Timeout)
        ->create(['duration_ms' => 55012, 'client_name' => 'Codex', 'created_at' => '2026-10-03 08:57:00']);
    ActivityEntry::factory()->through($star, $wiki, 'summarize')
        ->create(['kind' => ActivityKind::Prompt, 'duration_ms' => 1204, 'via' => StarAccessMode::SignedUrl, 'client_name' => null, 'created_at' => '2026-10-03 09:12:00']);
    ActivityEntry::factory()->through($star, $wiki, 'read_wiki_structure')
        ->create(['duration_ms' => 412, 'client_name' => 'Claude Code', 'created_at' => '2026-10-03 09:41:00']);

    $this->get(route('activity.index'))
        ->assertSeeTextInOrder([
            'Today', '3 calls · 1 not OK',
            '09:41', 'wiki__read_wiki_structure', 'Work', 'DeepWiki', 'Claude Code', 'OK', '412 ms',
            '09:12', 'wiki__summarize', 'Work', 'DeepWiki', 'Signed URL', 'prompt', 'OK', '1,204 ms',
            '08:57', 'wiki__ask_question', 'Work', 'DeepWiki', 'Codex', 'Timed out', '55.0 s',
        ])
        ->assertSee(route('stars.show', $star))
        ->assertSee(route('connections.show', $wiki))
        ->assertDontSeeText('No activity yet');
});

it('shows only the user\'s own activity and counts, and only their own Stars and Connections to filter by', function (): void {
    $wiki = Connection::factory()->for($this->user)->create(['name' => 'DeepWiki', 'handle' => 'wiki']);
    $star = Star::factory()->for($this->user)->create(['name' => 'Work']);
    ActivityEntry::factory()->through($star, $wiki, 'search')->create();
    $theirConnection = Connection::factory()->create(['name' => 'Their Linear', 'handle' => 'theirs']);
    $theirStar = Star::factory()->for($theirConnection->user)->create(['name' => 'Their Star']);
    ActivityEntry::factory()->through($theirStar, $theirConnection, 'secret_tool')->withStatus(ActivityStatus::Error)->count(2)->create();

    Livewire::test('pages::activity.index')
        ->assertSeeText(['wiki__search', '1 call'])
        ->assertDontSeeText('theirs__secret_tool')
        ->assertDontSeeText('not OK')
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
        ->assertSeeTextInOrder(['wiki__search', 'Deleted Star', 'Deleted Connection', 'OK'])
        ->assertDontSeeText('DeepWiki');
});

it('says why calls that named none of the Star\'s tools, or no name at all, were denied', function (): void {
    $star = Star::factory()->for($this->user)->create(['name' => 'Work']);
    ActivityEntry::factory()->withStatus(ActivityStatus::Denied)->create([
        'user_id' => $this->user->id, 'star_id' => $star->id, 'exposed_name' => 'nope__search', 'downstream_name' => null, 'created_at' => now()->subMinute(),
    ]);
    ActivityEntry::factory()->withStatus(ActivityStatus::Denied)->create([
        'user_id' => $this->user->id, 'star_id' => $star->id, 'exposed_name' => null, 'downstream_name' => null, 'created_at' => now()->subMinutes(2),
    ]);
    ActivityEntry::factory()->withStatus(ActivityStatus::Denied)->create([
        'user_id' => $this->user->id, 'star_id' => $star->id, 'kind' => ActivityKind::Prompt, 'exposed_name' => 'nope__review', 'downstream_name' => null, 'created_at' => now()->subMinutes(3),
    ]);

    $this->get(route('activity.index'))
        ->assertSeeTextInOrder([
            'nope__search', 'Work', 'Denied · unknown tool',
            'No name', 'Work', 'Denied · no name',
            'nope__review', 'Work', 'prompt', 'Denied · unknown prompt',
        ])
        ->assertDontSeeText('Deleted Connection');
});

it('says a denied tool is off only while it is still switched off in its Star', function (): void {
    $wiki = Connection::factory()->for($this->user)->create(['handle' => 'wiki']);
    ConnectionTool::factory()->for($wiki)->create(['name' => 'write_page', 'read_only' => false]);
    $star = Star::factory()->for($this->user)->including($wiki)->create();
    ActivityEntry::factory()->through($star, $wiki, 'write_page')->withStatus(ActivityStatus::Denied)->create();

    Livewire::test('pages::activity.index')->assertSeeText('Denied · tool off');

    resolve(SwitchStarTools::class)->handle($star, $wiki, true, ['write_page']);

    Livewire::test('pages::activity.index')
        ->assertSeeText('Denied')
        ->assertDontSeeText('tool off');
});

it('says a denied prompt is off only while it is still switched off in its Star', function (): void {
    $wiki = Connection::factory()->for($this->user)->create(['handle' => 'wiki']);
    ConnectionPrompt::factory()->for($wiki)->create(['name' => 'review']);
    $star = Star::factory()->for($this->user)->including($wiki)->create();
    resolve(SwitchStarPrompts::class)->handle($star, $wiki, false, ['review']);
    ActivityEntry::factory()->through($star, $wiki, 'review')->withStatus(ActivityStatus::Denied)->create(['kind' => ActivityKind::Prompt]);

    Livewire::test('pages::activity.index')->assertSeeText('Denied · prompt off');

    resolve(SwitchStarPrompts::class)->handle($star, $wiki, null, ['review']);

    Livewire::test('pages::activity.index')
        ->assertSeeText('Denied')
        ->assertDontSeeText('prompt off');
});

it('labels each kind of failure', function (ActivityStatus $status, string $label): void {
    $wiki = Connection::factory()->for($this->user)->create(['handle' => 'wiki']);
    $star = Star::factory()->for($this->user)->create();
    ActivityEntry::factory()->through($star, $wiki, 'search')->withStatus($status)->create();

    Livewire::test('pages::activity.index')->assertSeeTextInOrder(['wiki__search', $label]);
})->with([
    'needs sign-in' => [ActivityStatus::NeedsAuth, 'Needs sign-in'],
    'timed out' => [ActivityStatus::Timeout, 'Timed out'],
    'error' => [ActivityStatus::Error, 'Error'],
]);

it('escapes the names clients send', function (): void {
    $star = Star::factory()->for($this->user)->create();
    ActivityEntry::factory()->withStatus(ActivityStatus::Denied)->create([
        'user_id' => $this->user->id, 'star_id' => $star->id, 'exposed_name' => '<script>alert(1)</script>', 'downstream_name' => null,
    ]);

    $this->get(route('activity.index'))
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', escape: false)
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

it('gives each call its own UTC offset, and a day the clocks change both of them', function (): void {
    $this->travelTo('2026-09-27 20:00:00');
    $wiki = Connection::factory()->for($this->user)->create(['handle' => 'wiki']);
    $star = Star::factory()->for($this->user)->create();
    ActivityEntry::factory()->through($star, $wiki, 'before_the_change')->create(['created_at' => '2026-09-26 13:30:00']);
    ActivityEntry::factory()->through($star, $wiki, 'after_the_change')->create(['created_at' => '2026-09-26 14:30:00']);

    $this->withSession(['timezone' => 'Pacific/Auckland'])->get(route('activity.index', ['range' => '7d']))
        ->assertSeeTextInOrder(['Yesterday', 'UTC+12:00 → UTC+13:00', '03:30', 'wiki__after_the_change', '01:30', 'wiki__before_the_change'])
        ->assertSee('title="Sunday, September 27, 2026 03:30:00 UTC+13:00"', escape: false)
        ->assertSee('title="Sunday, September 27, 2026 01:30:00 UTC+12:00"', escape: false);
});

describe('days', function (): void {
    beforeEach(function (): void {
        $this->travelTo('2026-10-03 09:45:00');
        $wiki = Connection::factory()->for($this->user)->create(['handle' => 'wiki']);
        $star = Star::factory()->for($this->user)->create();

        ActivityEntry::factory()->through($star, $wiki, 'today_late')->create(['created_at' => '2026-10-03 09:30:00']);
        ActivityEntry::factory()->through($star, $wiki, 'today_early')->withStatus(ActivityStatus::Error)->create(['created_at' => '2026-10-03 00:30:00']);
        ActivityEntry::factory()->through($star, $wiki, 'yesterday')->create(['created_at' => '2026-10-02 18:22:00']);
        ActivityEntry::factory()->through($star, $wiki, 'tuesday')->withStatus(ActivityStatus::Timeout)->create(['created_at' => '2026-09-29 16:40:00']);
    });

    it('groups calls by day, newest first, with each day\'s count and how many weren\'t OK', function (): void {
        Livewire::withQueryParams(['range' => '7d'])->test('pages::activity.index')
            ->assertSeeTextInOrder([
                'Today', 'UTC', '2 calls · 1 not OK', '09:30', 'wiki__today_late', '00:30', 'wiki__today_early',
                'Yesterday', 'UTC', '1 call', '18:22', 'wiki__yesterday',
                'Tuesday, September 29', 'UTC', '1 call · 1 not OK', '16:40', 'wiki__tuesday',
            ]);
    });

    it('shows times and days in the timezone the browser reports, and remembers it', function (): void {
        Livewire::withQueryParams(['range' => '7d'])->test('pages::activity.index')
            ->call('useTimezone', 'Asia/Manila')
            ->assertSet('timezone', 'Asia/Manila')
            ->assertSeeTextInOrder([
                'Today', 'UTC+08:00', '3 calls', '1 not OK', '17:30', 'wiki__today_late', '08:30', 'wiki__today_early', '02:22', 'wiki__yesterday',
                'Wednesday, September 30', 'UTC+08:00', '1 call', '1 not OK', '00:40', 'wiki__tuesday',
            ])
            ->assertDontSeeText('Yesterday');

        Livewire::withQueryParams(['range' => '7d'])->test('pages::activity.index')
            ->assertSet('timezone', 'Asia/Manila')
            ->assertSeeTextInOrder(['Today', 'UTC+08:00', '17:30', 'wiki__today_late']);
    });

    it('ignores a timezone PHP doesn\'t know', function (): void {
        Livewire::test('pages::activity.index')
            ->call('useTimezone', 'Mars/Olympus_Mons')
            ->assertSet('timezone', 'UTC')
            ->assertSeeTextInOrder(['Today', 'UTC', '09:30', 'wiki__today_late']);

        expect(session('timezone'))->toBeNull();
    });
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

    it('reads the filters from the address bar, and shows them on their chips', function (): void {
        $work = Star::query()->where('name', 'Work')->sole();

        $this->get(route('activity.index', ['star' => $work->public_id, 'kind' => 'tool']))
            ->assertSeeTextInOrder(['Star', 'Work', 'Connection', 'Status', 'Kind', 'Tool', 'Clear', 'wiki__search'])
            ->assertDontSeeText('docs__summarize')
            ->assertDontSeeText('docs__fetch')
            ->assertDontSeeText('wiki__ask');
    });

    it('counts each day\'s calls with the filters applied', function (): void {
        Livewire::test('pages::activity.index')
            ->assertSeeText('4 calls · 2 not OK')
            ->set('connectionFilter', (string) Connection::query()->where('handle', 'docs')->value('id'))
            ->assertSeeText('2 calls · 1 not OK');
    });

    it('ignores filters that name another user\'s Star or Connection, or no status, kind or range', function (): void {
        $theirs = ActivityEntry::factory()->through(Star::factory()->create(), Connection::factory()->create(), 'secret_tool')->create();

        Livewire::withQueryParams([
            'star' => $theirs->star?->public_id,
            'connection' => (string) $theirs->connection_id,
            'status' => 'bogus',
            'kind' => 'nope',
            'range' => 'forever',
        ])->test('pages::activity.index')
            ->assertSet('starFilter', '')
            ->assertSet('connectionFilter', '')
            ->assertSet('statusFilter', '')
            ->assertSet('kindFilter', '')
            ->assertSet('range', '24h')
            ->assertSeeText(['wiki__search', 'docs__fetch', 'docs__summarize', 'wiki__ask'])
            ->assertDontSeeText('secret_tool');
    });

    it('says when no calls match, with a way to clear the filters', function (): void {
        Livewire::test('pages::activity.index')
            ->set('statusFilter', 'needs_auth')
            ->assertSeeText(['No matching activity', 'No calls match these filters in the last 24 hours.'])
            ->assertDontSeeText('wiki__search')
            ->assertDontSeeText('Show the last 30 days')
            ->call('clearFilters')
            ->assertSet('statusFilter', '')
            ->assertSeeText(['wiki__search', 'docs__fetch', 'docs__summarize', 'wiki__ask'])
            ->assertDontSeeText('No matching activity');
    });
});

describe('range', function (): void {
    beforeEach(function (): void {
        $this->travelTo('2026-10-03 09:45:00');
        $wiki = Connection::factory()->for($this->user)->create(['handle' => 'wiki']);
        $star = Star::factory()->for($this->user)->create();

        ActivityEntry::factory()->through($star, $wiki, 'minutes_ago')->create(['created_at' => now()->subMinutes(30)]);
        ActivityEntry::factory()->through($star, $wiki, 'hours_ago')->create(['created_at' => now()->subHours(3)]);
        ActivityEntry::factory()->through($star, $wiki, 'days_ago')->create(['created_at' => now()->subDays(2)]);
        ActivityEntry::factory()->through($star, $wiki, 'weeks_ago')->create(['created_at' => now()->subDays(10)]);
    });

    it('shows the last 24 hours unless the address bar names another range', function (?string $range, array $shown): void {
        $page = Livewire::withQueryParams(array_filter(['range' => $range]))->test('pages::activity.index');

        foreach (['wiki__minutes_ago', 'wiki__hours_ago', 'wiki__days_ago', 'wiki__weeks_ago'] as $name) {
            in_array($name, $shown, true) ? $page->assertSeeText($name) : $page->assertDontSeeText($name);
        }
    })->with([
        'by default' => [null, ['wiki__minutes_ago', 'wiki__hours_ago']],
        'the last hour' => ['1h', ['wiki__minutes_ago']],
        'the last 24 hours' => ['24h', ['wiki__minutes_ago', 'wiki__hours_ago']],
        'the last 7 days' => ['7d', ['wiki__minutes_ago', 'wiki__hours_ago', 'wiki__days_ago']],
        'the last 30 days' => ['30d', ['wiki__minutes_ago', 'wiki__hours_ago', 'wiki__days_ago', 'wiki__weeks_ago']],
    ]);

    it('offers the last 30 days when no calls happened in the range but earlier ones did', function (): void {
        $this->travel(1)->days();

        Livewire::test('pages::activity.index')
            ->assertSeeText(['No matching activity', 'No calls in the last 24 hours.', 'Show the last 30 days'])
            ->assertDontSeeText('Clear filters')
            ->set('range', '30d')
            ->assertSet('range', '30d')
            ->assertSeeText(['wiki__minutes_ago', 'wiki__weeks_ago'])
            ->assertDontSeeText('No matching activity');
    });

    it('doesn\'t offer the last 30 days when there are no earlier calls to show', function (): void {
        $this->travel(31)->days();

        Livewire::test('pages::activity.index')
            ->assertSeeText('No calls in the last 24 hours.')
            ->assertDontSeeText('Show the last 30 days');
    });
});

describe('live updates', function (): void {
    it('polls for new calls and shows when it last updated', function (): void {
        $this->travelTo('2026-10-03 09:45:00');
        $wiki = Connection::factory()->for($this->user)->create(['handle' => 'wiki']);
        $star = Star::factory()->for($this->user)->create();
        $page = Livewire::test('pages::activity.index')
            ->assertSeeHtml('wire:poll.5s')
            ->assertSeeText(['Live · updated 09:45', 'No activity yet']);

        $this->travelTo('2026-10-03 09:46:00');
        ActivityEntry::factory()->through($star, $wiki, 'search')->create();

        $page->call('$refresh')
            ->assertSeeText(['Live · updated 09:46', 'wiki__search'])
            ->assertDontSeeText('No activity yet');
    });

    it('stops polling while paused, and starts again on resume', function (): void {
        Livewire::test('pages::activity.index')
            ->call('pause')
            ->assertDontSeeHtml('wire:poll')
            ->assertSeeText(['Paused · updated', 'Resume'])
            ->call('resume')
            ->assertSeeHtml('wire:poll.5s')
            ->assertSeeText(['Live · updated', 'Pause']);
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

    it('pages through the activity 25 calls at a time, newest first, counting the whole day on every page', function (): void {
        Livewire::test('pages::activity.index')
            ->assertSeeTextInOrder(['Yesterday', '30 calls', 'wiki__call_30', 'wiki__call_29', 'wiki__call_06'])
            ->assertDontSeeText('wiki__call_05')
            ->call('nextPage')
            ->assertSeeTextInOrder(['Yesterday', '30 calls', 'wiki__call_05', 'wiki__call_01'])
            ->assertDontSeeText('wiki__call_06');
    });

    it('goes back to the first page when a filter changes', function (): void {
        Livewire::test('pages::activity.index')
            ->call('nextPage')
            ->set('statusFilter', 'ok')
            ->assertSeeText('wiki__call_30')
            ->assertDontSeeText('wiki__call_05');
    });

    it('goes back to the first page when the range changes', function (): void {
        Livewire::test('pages::activity.index')
            ->call('nextPage')
            ->set('range', '7d')
            ->assertSeeText('wiki__call_30')
            ->assertDontSeeText('wiki__call_05');
    });

    it('shows the last page for a page past the end', function (): void {
        Livewire::withQueryParams(['page' => 9])->test('pages::activity.index')
            ->assertSeeTextInOrder(['wiki__call_05', 'wiki__call_01'])
            ->assertDontSeeText('No matching activity');
    });
});
