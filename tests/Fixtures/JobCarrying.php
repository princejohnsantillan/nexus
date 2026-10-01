<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A queued job that carries whatever it is given in a public property, for
 * testing what ends up in a queue payload. It deliberately doesn't use
 * SerializesModels, so a model is serialized whole.
 */
class JobCarrying implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public mixed $cargo) {}

    public function handle(): void {}
}
