<?php

declare(strict_types=1);

use App\Models\Connection;
use App\Models\Star;
use App\Models\User;
use App\Stars\StarInstructions;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('says what the Star is, how tools are named, and which account each Connection is', function (): void {
    $github = Connection::factory()->for($this->user)->create(['name' => 'GitHub', 'handle' => 'github', 'account_identity' => 'octocat', 'description' => 'work repositories']);
    $wiki = Connection::factory()->for($this->user)->create(['name' => 'DeepWiki', 'handle' => 'wiki']);
    $star = Star::factory()->for($this->user)->including($github, $wiki)->create(['name' => 'Work', 'description' => 'For coding at work.']);

    expect(resolve(StarInstructions::class)->for($star))->toBe(<<<'TEXT'
        Tools from the "Work" Star in Nexus. Each tool's name starts with the handle of the Connection it belongs to and two underscores: github__search_issues is the search_issues tool of the Connection with the handle github.

        For coding at work.

        Connections:
        - wiki: DeepWiki
        - github: GitHub · octocat — use for: work repositories
        TEXT);
});

it('keeps to 2,048 characters by shortening the notes, then leaving them out, then cutting', function (int $connections, int $nameLength, Closure $expect): void {
    for ($i = 0; $i < $connections; $i++) {
        Connection::factory()->for($this->user)->create([
            'name' => str_pad("Account {$i} ", $nameLength, 'n'),
            'handle' => "account-{$i}",
            'description' => str_repeat('d', 200),
        ]);
    }

    $star = Star::factory()->for($this->user)->create();
    $star->connections()->attach($this->user->connections()->pluck('id'));

    $instructions = resolve(StarInstructions::class)->for($star);

    expect(mb_strlen($instructions))->toBeLessThanOrEqual(2048);
    $expect($instructions);
})->with([
    'shortened notes' => [9, 20, fn (string $text): mixed => expect($text)->toContain('use for: '.str_repeat('d', 79).'…')->not->toContain(str_repeat('d', 81))],
    'no notes' => [16, 20, fn (string $text): mixed => expect($text)->not->toContain('use for:')->toContain('- account-11: Account 11')],
    'cut' => [25, 100, fn (string $text): mixed => expect($text)->toEndWith('…')->not->toContain('use for:')],
]);
