<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use DateTimeImmutable;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use stdClass;
use Throwable;

/**
 * Distinctive text for a fake downstream server to send, and a watch over
 * every place Nexus must never copy it, or even its start (PREFIX), to:
 *
 *     $canary = DownstreamCanary::watch();
 *     // … a server answers with DownstreamCanary::TEXT in its error …
 *     expect($canary->sightings())->toBe([]);
 *
 * It looks in each log record as the log channel writes it (with the
 * exceptions it carries, their previous exceptions and stack traces), in
 * the arguments recorded in those exceptions' traces, which an error page
 * or a reporter may show in full, and in every row of the failed_jobs,
 * jobs and activity_entries tables. PHP records trace arguments only with
 * `zend.exception_ignore_args` off, as on a development machine such as
 * Herd; CI's PHP, like production's, leaves them out.
 *
 * failures(), someFailures() and tokenEndpointFailures() are datasets of
 * the ways a server can fail, each carrying the text, for
 * FakeMcpServer::respondTo() and FakeAuthorizationServer::respondTo();
 * oddTokenLifetimes() are lifetimes a token response can state that Nexus
 * can't use.
 */
final class DownstreamCanary
{
    /**
     * The text a fake server sends in its errors, response bodies and headers.
     */
    public const string TEXT = 'canary-9f2c-downstream-text';

    /**
     * The start of the text, which is still distinctive. A stack trace
     * written as text keeps only the first 15 bytes of each string argument,
     * so an argument holding the text after a few other characters (such as
     * `{"` in a JSON body) shows only this much of it.
     */
    public const string PREFIX = 'canary-9f2c';

    /**
     * Where the text turned up in the log so far. Only text is kept, never
     * the exceptions themselves, whose traces would keep each test's
     * application alive.
     *
     * @var list<string>
     */
    private array $logSightings = [];

    private function __construct() {}

    /**
     * Every way an MCP server can answer a request badly that Nexus tells
     * apart, each carrying the text: as a dataset of FakeMcpServer responders.
     *
     * @return array<string, array{Closure(stdClass, Request): PromiseInterface}>
     */
    public static function failures(): array
    {
        $text = self::TEXT;

        return [
            'a JSON-RPC error' => [FakeMcpServer::error(-32000, $text)],
            'a JSON-RPC error with data' => [fn (stdClass $message): PromiseInterface => Http::response(['jsonrpc' => '2.0', 'id' => $message->id ?? null, 'error' => ['code' => -32603, 'message' => $text, 'data' => ['detail' => $text]]])],
            'a JSON-RPC error with a placeholder id' => [FakeMcpServer::errorWithId('server-error', -32000, $text)],
            'a JSON-RPC error over HTTP 500' => [FakeMcpServer::error(-32603, $text, 500)],
            'HTTP 500' => [FakeMcpServer::httpStatus(500, $text)],
            'HTTP 404' => [FakeMcpServer::httpStatus(404, $text)],
            'HTTP 400' => [FakeMcpServer::httpStatus(400, $text)],
            'HTTP 401 with a challenge' => [FakeMcpServer::httpStatus(401, $text, ['WWW-Authenticate' => 'Bearer error="invalid_token", error_description="'.$text.'"'])],
            'HTTP 403' => [FakeMcpServer::httpStatus(403, $text)],
            'malformed JSON' => [FakeMcpServer::raw('{"jsonrpc":"2.0","error":"'.$text)],
            'JSON that isn\'t JSON-RPC' => [FakeMcpServer::raw(json_encode(['message' => $text], JSON_THROW_ON_ERROR))],
            'an answer to another request' => [FakeMcpServer::raw('{"jsonrpc":"2.0","id":999,"result":{"text":"'.$text.'"}}')],
            'a result that isn\'t an object' => [FakeMcpServer::jsonRpcResult(json_encode($text, JSON_THROW_ON_ERROR))],
            'an event stream with an error' => [fn (stdClass $message): PromiseInterface => Http::response("event: message\ndata: ".json_encode(['jsonrpc' => '2.0', 'id' => $message->id ?? null, 'error' => ['code' => -32000, 'message' => $text]])."\n\n", 200, ['Content-Type' => 'text/event-stream'])],
            'an event stream with a request from the server' => [FakeMcpServer::raw('data: {"jsonrpc":"2.0","id":7,"method":"'.$text.'"}'."\n\n", 'text/event-stream')],
            'a broken connection' => [fn (stdClass $message, Request $request): PromiseInterface => self::brokenConnection($request)],
            'a transfer error carrying the response' => [fn (stdClass $message, Request $request): PromiseInterface => self::brokenTransfer($request)],
            'a timeout' => [fn (stdClass $message, Request $request): PromiseInterface => Create::rejectionFor(new NetworkTimeoutException('cURL error 28: '.$text, $request->toPsrRequest()))],
        ];
    }

    /**
     * One failure of each kind from failures(): a JSON-RPC error, an HTTP
     * error, a sign-in challenge, malformed JSON and a broken transfer.
     *
     * @return array<string, array{Closure(stdClass, Request): PromiseInterface}>
     */
    public static function someFailures(): array
    {
        return Arr::only(self::failures(), ['a JSON-RPC error with data', 'HTTP 500', 'HTTP 401 with a challenge', 'malformed JSON', 'a transfer error carrying the response']);
    }

    /**
     * Every way an OAuth token endpoint can answer a request badly, each
     * carrying the text: as a dataset of FakeAuthorizationServer responders.
     *
     * @return array<string, array{Closure(Request): PromiseInterface}>
     */
    public static function tokenEndpointFailures(): array
    {
        $text = self::TEXT;

        return [
            'a refusal with a description' => [fn (): PromiseInterface => Http::response(['error' => 'invalid_grant', 'error_description' => $text], 400)],
            'a refusal with its own error code' => [fn (): PromiseInterface => Http::response(['error' => $text, 'error_description' => $text, 'error_uri' => 'https://auth.example.com/'.$text], 400)],
            'a passing error' => [fn (): PromiseInterface => Http::response(['error' => 'temporarily_unavailable', 'error_description' => $text], 503)],
            'an error with HTTP 200' => [fn (): PromiseInterface => Http::response(['error' => $text], 200)],
            'HTTP 500' => [fn (): PromiseInterface => Http::response($text, 500)],
            'malformed JSON' => [fn (): PromiseInterface => Http::response('{"access_token":"'.$text, 200)],
            'no access token' => [fn (): PromiseInterface => Http::response(['token_type' => $text], 200)],
            'an access token that can\'t be sent' => [fn (): PromiseInterface => Http::response(['access_token' => $text."\n", 'token_type' => 'Bearer', 'expires_in' => 3600], 200)],
            'a broken connection' => [self::brokenConnection(...)],
            'a transfer error carrying the response' => [self::brokenTransfer(...)],
        ];
    }

    /**
     * Lifetimes (`expires_in`) a token response can state that Nexus can't
     * use, as a dataset: each should count as no stated expiry.
     *
     * @return array<string, array{mixed}>
     */
    public static function oddTokenLifetimes(): array
    {
        return [
            'the largest integer' => [PHP_INT_MAX],
            'digits beyond the largest integer' => [str_repeat('9', 30)],
            'more than ten years' => [10 * 365 * 24 * 60 * 60 + 1],
            'a negative lifetime' => [-60],
            'a fraction' => [3600.5],
            'a decimal string' => ['3600.5'],
            'not a number' => [self::TEXT],
        ];
    }

    /**
     * Curl failing mid-request with an error that quotes the text.
     */
    public static function brokenConnection(Request $request): PromiseInterface
    {
        return Http::failedConnection('cURL error 56: '.self::TEXT)($request);
    }

    /**
     * Curl failing after the response arrived, which the HTTP client turns
     * into a RequestException quoting the response's body.
     */
    public static function brokenTransfer(Request $request): PromiseInterface
    {
        return Create::rejectionFor(new ResponseException('cURL error 18: '.self::TEXT, $request->toPsrRequest(), new PsrResponse(502, [], self::TEXT)));
    }

    /**
     * Start watching the log.
     */
    public static function watch(): self
    {
        $canary = new self;

        Event::listen(MessageLogged::class, $canary->record(...));

        return $canary;
    }

    /**
     * Where the text turned up, one line per place; empty when it is nowhere.
     *
     * @return list<string>
     */
    public function sightings(): array
    {
        $sightings = $this->logSightings;

        foreach (['failed_jobs', 'jobs', 'activity_entries'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                $columns = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                if (str_contains($columns, self::PREFIX)) {
                    $sightings[] = "{$table} row: {$columns}";
                }
            }
        }

        return $sightings;
    }

    private function record(MessageLogged $logged): void
    {
        $formatter = new LineFormatter(allowInlineLineBreaks: true, includeStacktraces: true);

        $record = $formatter->format(new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'testing',
            level: Level::fromName($logged->level),
            message: $logged->message,
            context: $logged->context,
        ));

        if (str_contains($record, self::PREFIX)) {
            $this->logSightings[] = 'log record: '.$record;
        }

        array_walk_recursive($logged->context, function (mixed $value): void {
            if ($value instanceof Throwable && TraceArguments::contain($value, self::PREFIX)) {
                $this->logSightings[] = 'stack trace arguments of a logged '.$value::class;
            }
        });
    }
}
