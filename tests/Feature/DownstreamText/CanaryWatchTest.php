<?php

declare(strict_types=1);

use App\Models\ActivityEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\DownstreamCanary;

/*
 * The watch the other tests in this folder rely on: it must spot the
 * canary wherever it could be copied to.
 */

beforeEach(function (): void {
    $this->canary = DownstreamCanary::watch();
});

it('spots the text in a log record', function (): void {
    Log::warning('The server said: '.DownstreamCanary::TEXT);

    expect($this->canary->sightings())->toHaveCount(1)
        ->and($this->canary->sightings()[0])->toStartWith('log record: ');
});

it('spots the start of the text, as a stack trace keeps it', function (): void {
    Log::error('Stack trace: #0 RawJson::member(\'{"'.substr(DownstreamCanary::TEXT, 0, 13).'...\', \'id\')');

    expect($this->canary->sightings())->toHaveCount(1);
});

it('spots the text in an exception a log record carries, and in the exceptions it wraps', function (): void {
    report(new RuntimeException('Nexus\'s own words', previous: new RuntimeException(DownstreamCanary::TEXT)));

    expect($this->canary->sightings())->toHaveCount(1);
});

it('spots the text in the stack trace arguments of a logged exception, where PHP records them', function (): void {
    $throw = function (string $body): never {
        throw new RuntimeException('Nexus\'s own words');
    };
    $ignoredArguments = ini_set('zend.exception_ignore_args', '0');

    try {
        $throw(DownstreamCanary::TEXT);
    } catch (RuntimeException $exception) {
        report($exception);
    } finally {
        ini_set('zend.exception_ignore_args', (string) $ignoredArguments);
    }

    expect($this->canary->sightings())->toContain('stack trace arguments of a logged '.RuntimeException::class);
});

it('spots the text in a failed job and an activity entry', function (): void {
    DB::table('failed_jobs')->insert(['uuid' => 'uuid-1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => DownstreamCanary::TEXT]);
    ActivityEntry::factory()->create(['exposed_name' => DownstreamCanary::TEXT]);

    expect($this->canary->sightings())->toHaveCount(2);
});
