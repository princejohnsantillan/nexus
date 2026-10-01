<?php

declare(strict_types=1);

use App\Actions\SwitchStarTools;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\StarToolSwitch;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

beforeEach(function (): void {
    $user = User::factory()->create();
    $this->connection = Connection::factory()->for($user)->create();
    ConnectionTool::factory()->for($this->connection)->create(['name' => 'write_page']);
    $this->star = Star::factory()->for($user)->including($this->connection)->create();
});

it('gives a switch only to tools in the Connection\'s catalog', function (): void {
    resolve(SwitchStarTools::class)->handle($this->star, $this->connection, true, ['write_page', 'not_a_tool']);

    expect($this->star->toolSwitches()->pluck('enabled', 'tool_name')->all())->toBe(['write_page' => true]);
});

it('writes nothing for a Connection the Star no longer includes', function (): void {
    $this->star->connections()->detach($this->connection);

    expect(fn () => resolve(SwitchStarTools::class)->handle($this->star, $this->connection, true))
        ->toThrow(ModelNotFoundException::class);

    expect(StarToolSwitch::query()->count())->toBe(0);
});

it('writes nothing for a deleted Star', function (): void {
    Star::query()->whereKey($this->star->id)->delete();

    expect(fn () => resolve(SwitchStarTools::class)->handle($this->star, $this->connection, true, ['write_page']))
        ->toThrow(ModelNotFoundException::class);

    expect(StarToolSwitch::query()->count())->toBe(0);
});
