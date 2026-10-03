<?php

declare(strict_types=1);

use App\Actions\SwitchStarTools;
use App\Enums\ConnectionStatus;
use App\Enums\NewToolPolicy;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\User;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConnectionOAuthFlow;
use Tests\Support\FakeMcpServer;

beforeEach(function (): void {
    config(['app.url' => 'https://nexus.test']);

    $this->user = User::factory()->create();

    $this->actingAs($this->user);
});

/**
 * Text with its runs of whitespace squeezed to single spaces.
 */
function squishedText(?string $text): string
{
    return trim((string) preg_replace('/\s+/', ' ', (string) $text));
}

/**
 * What the sidebar says about Connections that need attention: the count
 * beside Connections, the "Action required" card's text and where its fix
 * button leads, each null when it isn't shown.
 *
 * @return array{count: string|null, card: string|null, fix: string|null}
 */
function sidebarProblems(string $html): array
{
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $count = $document->querySelector('[data-flux-sidebar] [data-connection-problems]');
    $card = $document->querySelector('[data-flux-sidebar] [data-action-required]');

    return [
        'count' => $count instanceof Element ? squishedText($count->textContent) : null,
        'card' => $card instanceof Element ? squishedText($card->textContent) : null,
        'fix' => $card?->querySelector('[data-action-required-fix]')?->getAttribute('href'),
    ];
}

/**
 * Each Star card's footer by the Star's name: its text, and where its
 * button leads (null without one).
 *
 * @return array<string, array{text: string, fix: string|null}>
 */
function starCardFooters(string $html): array
{
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $footers = [];

    foreach ($document->querySelectorAll('[data-star-card]') as $card) {
        $footer = $card->querySelector('[data-star-card-footer]');

        $footers[squishedText($card->querySelector('h2')?->textContent)] = [
            'text' => squishedText($footer?->textContent),
            'fix' => $footer?->querySelector('a')?->getAttribute('href'),
        ];
    }

    return $footers;
}

/**
 * The banners under a Star's header: each one's text and where its fix leads.
 *
 * @return list<array{text: string, fix: string|null}>
 */
function starProblemBanners(string $html): array
{
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $banners = [];

    foreach ($document->querySelectorAll('[data-star-problem]') as $banner) {
        $banners[] = ['text' => squishedText($banner->textContent), 'fix' => $banner->querySelector('a')?->getAttribute('href')];
    }

    return $banners;
}

it('shows a Connection that needs sign-in beside Connections and in an Action required card on every app page', function (string $route): void {
    $notion = Connection::factory()->for($this->user)->oauth()->create(['name' => 'Notion']);
    ConnectionTool::factory()->for($notion)->count(2)->create(['read_only' => true]);
    Star::factory()->for($this->user)->including($notion)->create(['name' => 'Research']);

    $html = $this->get(route($route))->assertOk()->getContent();

    expect(sidebarProblems($html))->toBe([
        'count' => '1 needs attention',
        'card' => 'Action required Notion needs you to sign in again. 2 tools in Research are unavailable. Reconnect Notion',
        'fix' => route('connections.connect', $notion),
    ]);
})->with(['stars.index', 'connections.index', 'activity.index', 'settings.index']);

it('offers to open a Connection whose fix is on its page', function (Closure $makeConnection, string $card): void {
    $connection = $makeConnection($this->user);

    $html = $this->get(route('activity.index'))->assertOk()->getContent();

    expect(sidebarProblems($html))->toBe([
        'count' => '1 needs attention',
        'card' => $card,
        'fix' => route('connections.show', $connection),
    ]);
})->with([
    'an error' => [
        fn (User $user): Connection => Connection::factory()->for($user)->failed()->create(['name' => 'Linear']),
        'Action required Nexus couldn\'t load the tools of Linear. Open Linear',
    ],
    'a header sign-in the server refused' => [
        fn (User $user): Connection => Connection::factory()->for($user)->withHeader()->create(['name' => 'Linear', 'status' => ConnectionStatus::NeedsAuth]),
        'Action required Linear needs you to sign in again. Open Linear',
    ],
]);

it('names the first Connection that needs attention and counts the others', function (): void {
    Connection::factory()->for($this->user)->oauth()->create(['name' => 'Notion']);
    $linear = Connection::factory()->for($this->user)->failed()->create(['name' => 'Linear']);
    $github = Connection::factory()->for($this->user)->connected()->create(['name' => 'GitHub']);
    ConnectionTool::factory()->for($linear)->count(3)->create(['read_only' => true]);
    Star::factory()->for($this->user)->including($linear, $github)->create(['name' => 'Work']);
    Star::factory()->for($this->user)->including($linear)->create(['name' => 'Personal']);

    $html = $this->get(route('stars.index'))->assertOk()->getContent();

    expect(sidebarProblems($html))->toBe([
        'count' => '2 need attention',
        'card' => 'Action required Nexus couldn\'t load the tools of Linear. 6 tools in Personal and Work are unavailable. 1 more Connection needs attention Open Linear',
        'fix' => route('connections.show', $linear),
    ]);
});

it('shows nothing in the sidebar while every Connection works', function (): void {
    Connection::factory()->for($this->user)->connected()->create();
    Connection::factory()->for($this->user)->create();

    $html = $this->get(route('stars.index'))->assertOk()->getContent();

    expect(sidebarProblems($html))->toBe(['count' => null, 'card' => null, 'fix' => null]);
});

it('never shows another user\'s Connections, even one put in the user\'s Star', function (): void {
    $theirs = Connection::factory()->oauth()->create(['name' => 'Their Notion']);
    ConnectionTool::factory()->for($theirs)->create(['read_only' => true]);
    Star::factory()->for($theirs->user)->including($theirs)->create();
    $star = Star::factory()->for($this->user)->create(['name' => 'Mine']);
    DB::table('connection_star')->insert(['star_id' => $star->id, 'connection_id' => $theirs->id]);

    $stars = $this->get(route('stars.index'))->assertOk()->getContent();
    $page = $this->get(route('stars.show', $star))->assertOk()->getContent();

    expect(sidebarProblems($stars))->toBe(['count' => null, 'card' => null, 'fix' => null])
        ->and(starCardFooters($stars))->toBe(['Mine' => ['text' => 'Waiting for its first call…', 'fix' => null]])
        ->and(starProblemBanners($page))->toBe([]);
});

it('puts the problem in the footer of each Star card that includes the Connection, in place of its last call', function (): void {
    $notion = Connection::factory()->for($this->user)->oauth()->create(['name' => 'Notion']);
    $linear = Connection::factory()->for($this->user)->failed()->create(['name' => 'Linear']);
    $wiki = Connection::factory()->for($this->user)->connected()->create(['name' => 'DeepWiki']);
    Star::factory()->for($this->user)->including($notion, $wiki)->create(['name' => 'Research']);
    Star::factory()->for($this->user)->including($notion, $linear)->create(['name' => 'Personal']);
    Star::factory()->for($this->user)->including($wiki)->create(['name' => 'Work']);

    $html = $this->get(route('stars.index'))->assertOk()->getContent();

    expect(starCardFooters($html))->toBe([
        'Personal' => ['text' => 'Linear has an error and 1 more Open', 'fix' => route('connections.show', $linear)],
        'Research' => ['text' => 'Notion needs sign-in Reconnect', 'fix' => route('connections.connect', $notion)],
        'Work' => ['text' => 'Waiting for its first call…', 'fix' => null],
    ]);
});

it('warns on every page of a Star that includes the Connection, with how many of the Star\'s tools are unavailable', function (string $route): void {
    $notion = Connection::factory()->for($this->user)->oauth()->create(['name' => 'Notion', 'last_error' => 'The sign-in expired and the server didn\'t renew it. Reconnect to sign in again.']);
    ConnectionTool::factory()->for($notion)->count(2)->create(['read_only' => true]);
    ConnectionTool::factory()->for($notion)->create(['read_only' => false]);
    $wiki = Connection::factory()->for($this->user)->connected()->create(['name' => 'DeepWiki']);
    $research = Star::factory()->for($this->user)->including($notion, $wiki)->create(['name' => 'Research']);
    $work = Star::factory()->for($this->user)->including($wiki)->create(['name' => 'Work']);

    $researchPage = $this->get(route($route, $research))->assertOk()->getContent();
    $workPage = $this->get(route($route, $work))->assertOk()->getContent();

    expect(starProblemBanners($researchPage))->toBe([[
        'text' => 'Notion needs sign-in The sign-in expired and the server didn\'t renew it. Reconnect to sign in again. 2 of this Star\'s tools are unavailable until it\'s fixed. Reconnect Notion',
        'fix' => route('connections.connect', $notion),
    ]])
        ->and(starProblemBanners($workPage))->toBe([]);
})->with(['stars.show', 'stars.tools', 'stars.prompts', 'stars.access']);

it('warns about a Connection with an error and leads to its page', function (): void {
    $linear = Connection::factory()->for($this->user)->failed('The server answered with HTTP 503.')->create(['name' => 'Linear']);
    $star = Star::factory()->for($this->user)->including($linear)->create();

    $html = $this->get(route('stars.show', $star))->assertOk()->getContent();

    expect(starProblemBanners($html))->toBe([[
        'text' => 'Linear has an error The server answered with HTTP 503. None of its tools are on in this Star. Open Linear',
        'fix' => route('connections.show', $linear),
    ]]);
});

it('counts as unavailable only the Connection\'s tools that are on in the Star', function (NewToolPolicy $policy, int $unavailable): void {
    $notion = Connection::factory()->for($this->user)->oauth()->create(['name' => 'Notion']);
    ConnectionTool::factory()->for($notion)->create(['name' => 'search', 'read_only' => true]);
    ConnectionTool::factory()->for($notion)->create(['name' => 'fetch', 'read_only' => true]);
    ConnectionTool::factory()->for($notion)->create(['name' => 'update', 'read_only' => false]);
    ConnectionTool::factory()->for($notion)->create(['name' => 'export', 'read_only' => null]);
    $star = Star::factory()->for($this->user)->withPolicy($policy)->including($notion)->create();
    resolve(SwitchStarTools::class)->handle($star, $notion, false, ['fetch']);
    resolve(SwitchStarTools::class)->handle($star, $notion, true, ['update']);

    $html = $this->get(route('stars.show', $star))->assertOk()->getContent();

    expect(starProblemBanners($html)[0]['text'])->toContain(trans_choice(':count of this Star\'s tools is unavailable|:count of this Star\'s tools are unavailable', $unavailable));
})->with([
    'read-only tools on: search, and update switched on' => [NewToolPolicy::ReadOnly, 2],
    'all tools on: all but fetch, switched off' => [NewToolPolicy::All, 3],
    'all tools off: only update, switched on' => [NewToolPolicy::None, 1],
]);

it('clears every warning once the Connection signs in again', function (): void {
    $server = FakeMcpServer::at()->requireOAuth()->withTools([['name' => 'search', 'annotations' => ['readOnlyHint' => true]]]);
    $notion = Connection::factory()->for($this->user)->oauth()->create(['name' => 'Notion']);
    $star = Star::factory()->for($this->user)->including($notion)->create(['name' => 'Research']);
    expect(starProblemBanners($this->get(route('stars.show', $star))->getContent()))->toHaveCount(1);

    ConnectionOAuthFlow::signIn($this, $notion, $server->authorizationServer());

    $stars = $this->get(route('stars.index'))->assertOk()->getContent();
    $page = $this->get(route('stars.show', $star))->assertOk()->getContent();

    expect(sidebarProblems($stars))->toBe(['count' => null, 'card' => null, 'fix' => null])
        ->and(starCardFooters($stars))->toBe(['Research' => ['text' => 'Waiting for its first call…', 'fix' => null]])
        ->and(starProblemBanners($page))->toBe([]);
});

it('looks for problems in one query, however many there are', function (): void {
    $connections = Connection::factory()->for($this->user)->count(3)->sequence(
        fn (): array => ['status' => ConnectionStatus::NeedsAuth],
        fn (): array => ['status' => ConnectionStatus::Error],
    )->has(ConnectionTool::factory()->count(2), 'tools')->create();
    Star::factory()->for($this->user)->including(...$connections)->create();
    Star::factory()->for($this->user)->including($connections[0])->create();
    DB::enableQueryLog();

    $html = $this->get(route('settings.index'))->assertOk()->getContent();

    expect(sidebarProblems($html)['count'])->toBe('3 need attention')
        ->and(array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $query): bool => str_contains($query, 'from "connections"')))->toHaveCount(1);
});
