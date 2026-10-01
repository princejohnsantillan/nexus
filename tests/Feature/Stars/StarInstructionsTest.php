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

/**
 * Give the user this many Connections with every field as long as it may
 * be, and a Star of the longest name and description that includes them all.
 */
function longestStar(User $user, int $connections, int $nameLength = 100, bool $withIdentity = true): Star
{
    for ($i = 0; $i < $connections; $i++) {
        Connection::factory()->for($user)->create([
            'name' => str_pad(sprintf('Account %02d ', $i), $nameLength, 'n'),
            'handle' => str_pad(sprintf('account-%02d-', $i), 24, 'h'),
            'account_identity' => $withIdentity ? str_repeat('i', 100) : null,
            'description' => str_repeat('d', 200),
        ]);
    }

    $star = Star::factory()->for($user)->create(['name' => str_repeat('S', 100), 'description' => str_repeat('D', 500)]);
    $star->connections()->attach($user->connections()->pluck('id'));

    return $star;
}

it('shortens the "use for" notes, then leaves them out, to keep to 2,048 characters', function (int $connections, string $kept, string $leftOut): void {
    $instructions = resolve(StarInstructions::class)->for(longestStar($this->user, $connections, nameLength: 20, withIdentity: false));

    expect(mb_strlen($instructions))->toBeLessThanOrEqual(2048)
        ->and($instructions)->toContain(str_repeat('D', 500))->toContain($kept)->not->toContain($leftOut);
})->with([
    'shortened' => [5, 'use for: '.str_repeat('d', 79).'…', str_repeat('d', 80)],
    'left out' => [9, '- account-08-hhhhhhhhhhhhh: Account 08', 'use for:'],
]);

it('keeps a line with the handle and the start of the label for every Connection, however long they all are', function (bool $withIdentity): void {
    $instructions = resolve(StarInstructions::class)->for(longestStar($this->user, 25, withIdentity: $withIdentity));

    $lines = array_values(array_filter(explode("\n", $instructions), fn (string $line): bool => str_starts_with($line, '- ')));

    expect(mb_strlen($instructions))->toBeLessThanOrEqual(2048)
        ->and($lines)->toHaveCount(25)
        ->and($instructions)->toContain(str_repeat('D', 199).'…')->not->toContain(str_repeat('D', 200));

    foreach ($lines as $i => $line) {
        expect($line)->toStartWith(sprintf('- account-%02d-%s: Account %02d', $i, str_repeat('h', 13), $i))->toEndWith('…');
    }
})->with(['with detected identities' => true, 'without' => false]);
