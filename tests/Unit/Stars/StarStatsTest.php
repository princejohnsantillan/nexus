<?php

declare(strict_types=1);

use App\Stars\StarStats;

it('gives the share of calls that didn\'t end OK to one decimal place', function (int $calls, int $errors, ?string $rate): void {
    $stats = new StarStats(toolsOn: 0, tools: 0, calls: $calls, callsByHour: [], errors: $errors, lastCall: null);

    expect($stats->errorRate())->toBe($rate);
})->with([
    'no calls' => [0, 0, null],
    'no errors' => [10, 0, '0%'],
    'a whole percentage' => [4, 1, '25%'],
    'a fraction' => [1284, 3, '0.2%'],
    'rounded' => [3, 1, '33.3%'],
    'the smallest share shown' => [1000, 1, '0.1%'],
    'a few errors among many calls' => [10_000, 1, '<0.1%'],
    'a few successes among many errors' => [10_000, 9_999, '>99.9%'],
    'every call' => [10, 10, '100%'],
]);
