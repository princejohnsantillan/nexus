<?php

declare(strict_types=1);

use App\Actions\SwitchStarTools;
use App\Models\ActivityEntry;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\User;
use App\Stars\ToolDetailsReader;
use Illuminate\Support\Facades\DB;

it('reads a tool\'s Stars and calls in four queries, however many there are', function (int $stars): void {
    $user = User::factory()->create();
    $connection = Connection::factory()->for($user)->create();
    $tool = ConnectionTool::factory()->for($connection)->create(['name' => 'search']);

    foreach (range(1, $stars) as $number) {
        $star = Star::factory()->for($user)->including($connection)->create();
        resolve(SwitchStarTools::class)->handle($star, $connection, true, ['search']);
        ActivityEntry::factory()->through($star, $connection, 'search')->count(2)->create();
    }

    $tool->load('connection');
    DB::enableQueryLog();

    $details = resolve(ToolDetailsReader::class)->read($tool);

    expect(DB::getQueryLog())->toHaveCount(4);
    expect($details->stars)->toHaveCount($stars);
    expect($details->recentCalls)->toHaveCount(min(2 * $stars, 5));
})->with([1, 4]);
