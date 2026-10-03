<?php

declare(strict_types=1);

use App\Actions\SwitchStarPrompts;
use App\Models\Connection;
use App\Models\ConnectionPrompt;
use App\Models\Star;
use App\Models\User;
use App\Stars\StarPrompt;
use App\Stars\StarPrompts;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();

    $this->actingAs($this->user);

    $this->wiki = Connection::factory()->for($this->user)->create(['name' => 'DeepWiki', 'handle' => 'deepwiki']);
    $this->summarize = ConnectionPrompt::factory()->for($this->wiki)->create(['name' => 'summarize', 'title' => 'Summarize a page', 'description' => 'Summarizes one wiki page.']);
    $this->explain = ConnectionPrompt::factory()->for($this->wiki)->create(['name' => 'explain', 'title' => null, 'description' => 'Explains a concept.']);
    $this->star = Star::factory()->for($this->user)->including($this->wiki)->create(['name' => 'Work']);
});

/**
 * Each of the Star's prompts by exposed name: whether it is on and whether the user switched it.
 *
 * @return array<string, array{bool, bool|null}>
 */
function starPromptStates(Star $star): array
{
    return collect(resolve(StarPrompts::class)->prompts($star))
        ->mapWithKeys(fn (StarPrompt $prompt): array => [$prompt->name => [$prompt->enabled, $prompt->switch]])
        ->all();
}

/**
 * Match the Flux toast with this text.
 *
 * @return Closure(string, array<string, mixed>): bool
 */
function promptsToast(string $text): Closure
{
    return fn (string $event, array $params): bool => $params['slots']['text'] === $text;
}

it('lists every prompt grouped by Connection, on by default, with its exposed name, title and description', function (): void {
    $docs = Connection::factory()->for($this->user)->create(['name' => 'Docs', 'handle' => 'docs']);
    ConnectionPrompt::factory()->for($docs)->create(['name' => 'ask', 'description' => 'Asks the docs.']);
    $this->star->connections()->attach($docs);
    resolve(SwitchStarPrompts::class)->handle($this->star, $this->wiki, false, ['explain']);

    $this->get(route('stars.prompts', $this->star))
        ->assertOk()
        ->assertSee('<title>Star prompts · Nexus</title>', escape: false)
        ->assertSeeTextInOrder(['Work', 'Overview', 'Tools', 'Prompts', 'Access'])
        ->assertSeeTextInOrder([
            'DeepWiki', 'deepwiki', '1 of 2 on', 'All on', 'All off', 'Reset all',
            'deepwiki__explain', 'Explains a concept.', 'Your choice', 'Reset',
            'deepwiki__summarize', 'Summarize a page', 'Summarizes one wiki page.', 'Default',
            'Docs', 'docs', '1 of 1 on', 'docs__ask', 'Asks the docs.', 'Default',
        ])
        ->assertSee(route('connections.prompts', $this->wiki));
});

it('switches a prompt off and on as the user\'s own choice', function (): void {
    Livewire::test('pages::stars.prompts', ['star' => $this->star])
        ->call('switchPrompt', $this->summarize->id, false)
        ->assertSeeText('Your choice');

    expect(starPromptStates($this->star))->toBe(['deepwiki__explain' => [true, null], 'deepwiki__summarize' => [false, false]]);

    Livewire::test('pages::stars.prompts', ['star' => $this->star])->call('switchPrompt', $this->summarize->id, true);

    expect(starPromptStates($this->star))->toBe(['deepwiki__explain' => [true, null], 'deepwiki__summarize' => [true, true]]);
});

it('draws a switch afresh when its state changes, since Flux\'s switch keeps its own', function (): void {
    Livewire::test('pages::stars.prompts', ['star' => $this->star])
        ->assertSeeHtml('wire:key="switch-'.$this->summarize->id.'-on"')
        ->call('switchConnection', $this->wiki->id, 'off')
        ->assertSeeHtml('wire:key="switch-'.$this->summarize->id.'-off"')
        ->assertDontSeeHtml('wire:key="switch-'.$this->summarize->id.'-on"');
});

it('switches a prompt on its switch\'s change event, which Flux sends for a click, Enter or Space alike', function (): void {
    Livewire::test('pages::stars.prompts', ['star' => $this->star])
        ->assertSeeHtml('wire:key="switch-'.$this->summarize->id.'-on" wire:change="switchPrompt('.$this->summarize->id.', false)"')
        ->assertDontSeeHtml('wire:click="switchPrompt(');
});

it('resets a prompt to on by default', function (): void {
    resolve(SwitchStarPrompts::class)->handle($this->star, $this->wiki, false, ['summarize']);

    Livewire::test('pages::stars.prompts', ['star' => $this->star])
        ->assertSeeText('Your choice')
        ->call('resetPrompt', $this->summarize->id)
        ->assertDontSeeText('Your choice');

    expect(starPromptStates($this->star))->toBe(['deepwiki__explain' => [true, null], 'deepwiki__summarize' => [true, null]]);
});

it('switches all of a Connection\'s prompts on or off, or resets them', function (): void {
    $component = Livewire::test('pages::stars.prompts', ['star' => $this->star])
        ->call('switchConnection', $this->wiki->id, 'off')
        ->assertDispatched('toast-show', promptsToast('Switched off 2 DeepWiki prompts.'))
        ->assertSeeText('0 of 2 on');

    expect(starPromptStates($this->star))->toBe(['deepwiki__explain' => [false, false], 'deepwiki__summarize' => [false, false]]);

    $component->call('switchConnection', $this->wiki->id, 'on')
        ->assertDispatched('toast-show', promptsToast('Switched on 2 DeepWiki prompts.'));

    expect(starPromptStates($this->star))->toBe(['deepwiki__explain' => [true, true], 'deepwiki__summarize' => [true, true]]);

    $component->call('switchConnection', $this->wiki->id, 'reset')
        ->assertDispatched('toast-show', promptsToast('2 DeepWiki prompts are on by default again.'));

    expect(starPromptStates($this->star))->toBe(['deepwiki__explain' => [true, null], 'deepwiki__summarize' => [true, null]]);
});

it('keeps a switch by name through a catalog refresh, and drops leftovers when a whole Connection is switched', function (): void {
    resolve(SwitchStarPrompts::class)->handle($this->star, $this->wiki, false, ['summarize']);
    $this->summarize->delete();
    ConnectionPrompt::factory()->for($this->wiki)->create(['name' => 'summarize']);

    expect(starPromptStates($this->star)['deepwiki__summarize'])->toBe([false, false]);

    $this->wiki->prompts()->where('name', 'summarize')->delete();

    Livewire::test('pages::stars.prompts', ['star' => $this->star])->call('switchConnection', $this->wiki->id, 'off');

    expect($this->star->promptSwitches()->pluck('enabled', 'prompt_name')->all())->toBe(['explain' => false]);
});

it('does not switch prompts of Connections outside the Star', function (string $action): void {
    $outside = ConnectionPrompt::factory()->for(Connection::factory()->for($this->user))->create();
    $someoneElses = ConnectionPrompt::factory()->create();

    foreach ([$outside, $someoneElses] as $prompt) {
        Livewire::test('pages::stars.prompts', ['star' => $this->star])
            ->call($action, $prompt->id, false)
            ->assertNotFound();
    }

    Livewire::test('pages::stars.prompts', ['star' => $this->star])
        ->call('switchConnection', $outside->connection_id, 'off')
        ->assertNotFound();

    expect($this->star->promptSwitches()->count())->toBe(0);
})->with(['switchPrompt', 'resetPrompt']);

it('refuses an unknown bulk choice', function (): void {
    Livewire::test('pages::stars.prompts', ['star' => $this->star])
        ->call('switchConnection', $this->wiki->id, 'maybe')
        ->assertNotFound();

    expect($this->star->promptSwitches()->count())->toBe(0);
});

it('forgets a Connection\'s prompt switches when it is taken out of the Star', function (): void {
    resolve(SwitchStarPrompts::class)->handle($this->star, $this->wiki, false);

    Livewire::test('pages::stars.show', ['star' => $this->star])
        ->set('connectionIds', [])
        ->call('saveConnections');

    expect($this->star->promptSwitches()->count())->toBe(0);
});

it('says when the Star includes no Connections', function (): void {
    $star = Star::factory()->for($this->user)->create();

    Livewire::test('pages::stars.prompts', ['star' => $star])
        ->assertSeeText('No prompts yet')
        ->assertSeeText('This Star doesn\'t include any Connections.')
        ->assertSee(route('stars.show', $star));
});

it('says when a Connection has no prompts', function (): void {
    $this->star->connections()->attach(Connection::factory()->for($this->user)->create(['name' => 'Pending']));

    Livewire::test('pages::stars.prompts', ['star' => $this->star])
        ->assertSeeTextInOrder(['Pending', '0 of 0 on', 'Nexus has no prompts for this Connection.', 'Refresh its tools and prompts']);
});

it('escapes the titles and descriptions servers send', function (): void {
    ConnectionPrompt::factory()->for($this->wiki)->create(['title' => '<img src=x onerror=alert(1)>', 'description' => '<script>alert("prompt")</script>']);

    $this->get(route('stars.prompts', $this->star))
        ->assertOk()
        ->assertDontSee('<img src=x onerror=alert(1)>', escape: false)
        ->assertDontSee('<script>alert("prompt")</script>', escape: false);
});
