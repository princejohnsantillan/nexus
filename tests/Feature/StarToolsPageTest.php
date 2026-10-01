<?php

declare(strict_types=1);

use App\Actions\SwitchStarTools;
use App\Enums\NewToolPolicy;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\User;
use App\Stars\StarTool;
use App\Stars\StarToolset;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();

    $this->actingAs($this->user);

    $this->wiki = Connection::factory()->for($this->user)->create(['name' => 'DeepWiki', 'handle' => 'deepwiki']);
    $this->readPage = ConnectionTool::factory()->for($this->wiki)->create(['name' => 'read_page', 'title' => 'Read a page', 'description' => 'Reads one wiki page.', 'read_only' => true, 'open_world' => true]);
    $this->writePage = ConnectionTool::factory()->for($this->wiki)->create(['name' => 'write_page', 'title' => null, 'description' => 'Writes a page.', 'read_only' => false, 'destructive' => true]);
    $this->star = Star::factory()->for($this->user)->including($this->wiki)->create(['name' => 'Work']);
});

/**
 * Each of the Star's tools by exposed name: whether it is on and whether the user switched it.
 *
 * @return array<string, array{bool, bool|null}>
 */
function starToolStates(Star $star): array
{
    return collect(resolve(StarToolset::class)->tools($star))
        ->mapWithKeys(fn (StarTool $tool): array => [$tool->name => [$tool->enabled, $tool->switch]])
        ->all();
}

/**
 * Match the Flux toast with this text.
 *
 * @return Closure(string, array<string, mixed>): bool
 */
function toolsToast(string $text): Closure
{
    return fn (string $event, array $params): bool => $params['slots']['text'] === $text;
}

it('lists every tool grouped by Connection, with its exposed name, description, hints and who set it', function (): void {
    $docs = Connection::factory()->for($this->user)->create(['name' => 'Docs', 'handle' => 'docs']);
    ConnectionTool::factory()->for($docs)->create(['name' => 'search', 'description' => 'Searches the docs.', 'read_only' => true]);
    $this->star->connections()->attach($docs);
    resolve(SwitchStarTools::class)->handle($this->star, $this->wiki, true, ['write_page']);

    $this->get(route('stars.tools', $this->star))
        ->assertOk()
        ->assertSee('<title>Star tools · Nexus</title>', escape: false)
        ->assertSeeTextInOrder(['Work', 'Overview', 'Tools'])
        ->assertSeeTextInOrder(['New-tool policy', 'Read-only tools on', 'All tools on', 'All tools off'])
        ->assertSeeTextInOrder([
            'DeepWiki', 'deepwiki', '2 of 2 on', 'All on', 'All off', 'Reset to policy',
            'deepwiki__read_page', 'Read a page', 'Reads one wiki page.', 'Read-only', 'Open-world', 'Policy',
            'deepwiki__write_page', 'Writes a page.', 'Destructive', 'Your choice', 'Reset',
            'Docs', 'docs', '1 of 1 on', 'docs__search', 'Searches the docs.', 'Read-only', 'Policy',
        ])
        ->assertSee(route('connections.show', $this->wiki));
});

it('switches a tool on and off as the user\'s own choice', function (): void {
    Livewire::test('pages::stars.tools', ['star' => $this->star])
        ->call('switchTool', $this->writePage->id, true)
        ->assertSeeText('Your choice');

    expect(starToolStates($this->star))->toBe(['deepwiki__read_page' => [true, null], 'deepwiki__write_page' => [true, true]]);

    Livewire::test('pages::stars.tools', ['star' => $this->star])->call('switchTool', $this->writePage->id, false);
    Livewire::test('pages::stars.tools', ['star' => $this->star])->call('switchTool', $this->readPage->id, false);

    expect(starToolStates($this->star))->toBe(['deepwiki__read_page' => [false, false], 'deepwiki__write_page' => [false, false]]);
});

it('draws a switch afresh when its state changes, since Flux\'s switch keeps its own', function (): void {
    Livewire::test('pages::stars.tools', ['star' => $this->star])
        ->assertSeeHtml('wire:key="switch-'.$this->writePage->id.'-off"')
        ->call('switchConnection', $this->wiki->id, 'on')
        ->assertSeeHtml('wire:key="switch-'.$this->writePage->id.'-on"')
        ->assertDontSeeHtml('wire:key="switch-'.$this->writePage->id.'-off"');
});

it('resets a tool to the policy', function (): void {
    resolve(SwitchStarTools::class)->handle($this->star, $this->wiki, false, ['read_page']);

    Livewire::test('pages::stars.tools', ['star' => $this->star])
        ->assertSeeText('Your choice')
        ->call('resetTool', $this->readPage->id)
        ->assertDontSeeText('Your choice');

    expect(starToolStates($this->star))->toBe(['deepwiki__read_page' => [true, null], 'deepwiki__write_page' => [false, null]]);
});

it('switches all of a Connection\'s tools on or off, or resets them to the policy', function (): void {
    $component = Livewire::test('pages::stars.tools', ['star' => $this->star])
        ->call('switchConnection', $this->wiki->id, 'on')
        ->assertDispatched('toast-show', toolsToast('Switched on 2 DeepWiki tools.'));

    expect(starToolStates($this->star))->toBe(['deepwiki__read_page' => [true, true], 'deepwiki__write_page' => [true, true]]);

    $component->call('switchConnection', $this->wiki->id, 'off')
        ->assertDispatched('toast-show', toolsToast('Switched off 2 DeepWiki tools.'))
        ->assertSeeText('0 of 2 on');

    expect(starToolStates($this->star))->toBe(['deepwiki__read_page' => [false, false], 'deepwiki__write_page' => [false, false]]);

    $component->call('switchConnection', $this->wiki->id, 'reset')
        ->assertDispatched('toast-show', toolsToast('2 DeepWiki tools follow the policy again.'));

    expect(starToolStates($this->star))->toBe(['deepwiki__read_page' => [true, null], 'deepwiki__write_page' => [false, null]]);
});

it('drops switches for tools the server no longer lists when a whole Connection is switched', function (): void {
    resolve(SwitchStarTools::class)->handle($this->star, $this->wiki, true);
    $this->writePage->delete();

    Livewire::test('pages::stars.tools', ['star' => $this->star])->call('switchConnection', $this->wiki->id, 'off');

    expect($this->star->toolSwitches()->pluck('enabled', 'tool_name')->all())->toBe(['read_page' => false]);
});

it('filters the tools by exposed name or title', function (): void {
    Livewire::test('pages::stars.tools', ['star' => $this->star])
        ->set('search', 'WRITE')
        ->assertSeeText('deepwiki__write_page')
        ->assertDontSeeText('deepwiki__read_page')
        ->assertSeeText('Matching on')
        ->set('search', 'read a')
        ->assertSeeText('deepwiki__read_page')
        ->assertDontSeeText('deepwiki__write_page')
        ->set('search', 'nothing like this')
        ->assertSeeText('No tools match "nothing like this".')
        ->assertDontSeeText('Matching on');
});

it('switches only the matching tools while filtering', function (): void {
    Livewire::test('pages::stars.tools', ['star' => $this->star])
        ->set('search', 'read')
        ->call('switchConnection', $this->wiki->id, 'off')
        ->assertDispatched('toast-show', toolsToast('Switched off 1 DeepWiki tool.'));

    expect(starToolStates($this->star))->toBe(['deepwiki__read_page' => [false, false], 'deepwiki__write_page' => [false, null]]);
});

it('changes the new-tool policy, which decides every tool without a switch', function (): void {
    resolve(SwitchStarTools::class)->handle($this->star, $this->wiki, false, ['read_page']);

    Livewire::test('pages::stars.tools', ['star' => $this->star])
        ->assertSet('policy', 'read_only')
        ->set('policy', 'all')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', toolsToast('New-tool policy: All tools on.'));

    expect($this->star->refresh()->new_tool_policy)->toBe(NewToolPolicy::All)
        ->and(starToolStates($this->star))->toBe(['deepwiki__read_page' => [false, false], 'deepwiki__write_page' => [true, null]]);
});

it('refuses a policy that doesn\'t exist', function (): void {
    Livewire::test('pages::stars.tools', ['star' => $this->star])
        ->set('policy', 'everything')
        ->assertHasErrors(['policy']);

    expect($this->star->refresh()->new_tool_policy)->toBe(NewToolPolicy::ReadOnly);
});

it('does not switch tools of Connections outside the Star', function (string $action): void {
    $outside = ConnectionTool::factory()->for(Connection::factory()->for($this->user))->create();
    $someoneElses = ConnectionTool::factory()->create();

    foreach ([$outside, $someoneElses] as $tool) {
        Livewire::test('pages::stars.tools', ['star' => $this->star])
            ->call($action, $tool->id, true)
            ->assertNotFound();
    }

    Livewire::test('pages::stars.tools', ['star' => $this->star])
        ->call('switchConnection', $outside->connection_id, 'on')
        ->assertNotFound();

    expect($this->star->toolSwitches()->count())->toBe(0);
})->with(['switchTool', 'resetTool']);

it('refuses an unknown bulk choice', function (): void {
    Livewire::test('pages::stars.tools', ['star' => $this->star])
        ->call('switchConnection', $this->wiki->id, 'maybe')
        ->assertNotFound();

    expect($this->star->toolSwitches()->count())->toBe(0);
});

it('says when the Star includes no Connections', function (): void {
    $star = Star::factory()->for($this->user)->create();

    Livewire::test('pages::stars.tools', ['star' => $star])
        ->assertSeeText('No tools yet')
        ->assertSeeText('This Star doesn\'t include any Connections.')
        ->assertSee(route('stars.show', $star));
});

it('says when a Connection has no tools loaded', function (): void {
    $this->star->connections()->attach(Connection::factory()->for($this->user)->create(['name' => 'Pending']));

    Livewire::test('pages::stars.tools', ['star' => $this->star])
        ->assertSeeTextInOrder(['Pending', '0 of 0 on', 'Nexus has no tools for this Connection yet.', 'Refresh its tools']);
});

it('escapes the titles and descriptions servers send', function (): void {
    ConnectionTool::factory()->for($this->wiki)->create(['title' => '<img src=x onerror=alert(1)>', 'description' => '<script>alert("tool")</script>']);

    $this->get(route('stars.tools', $this->star))
        ->assertOk()
        ->assertDontSee('<img src=x onerror=alert(1)>', escape: false)
        ->assertDontSee('<script>alert("tool")</script>', escape: false);
});
