<?php

declare(strict_types=1);

use App\Downstream\RawJson;

it('returns an object member as the exact text sent', function (string $json, string $key, string $member): void {
    expect(RawJson::member($json, $key))->toBe($member);
})->with([
    'object' => ['{"id":1,"result":{"tools":[],"meta":{}}}', 'result', '{"tools":[],"meta":{}}'],
    'long number' => ['{"n":18446744073709551615,"m":1e400}', 'n', '18446744073709551615'],
    'number too large for a float' => ['{"n":18446744073709551615,"m":1e400}', 'm', '1e400'],
    'string with quotes and braces' => ['{"a":"say \"}\" and \\\\","b":2}', 'a', '"say \"}\" and \\\\"'],
    'whitespace around it' => ["{ \"a\" :\n [ 1 , {} ] \t}", 'a', '[ 1 , {} ]'],
    'escaped key' => ['{"result":true}', 'result', 'true'],
    'last of duplicates' => ['{"a":1,"a":2}', 'a', '2'],
    'braces inside a nested string' => ['{"a":{"b":"}]{["},"c":3}', 'c', '3'],
]);

it('finds no member in JSON that is not an object or lacks it', function (string $json): void {
    expect(RawJson::member($json, 'result'))->toBeNull();
})->with([
    'missing' => ['{"error":{}}'],
    'empty object' => ['{}'],
    'array' => ['[{"result":1}]'],
    'string' => ['"result"'],
    'unterminated' => ['{"result":{"tools":['],
    'unterminated string' => ['{"result":"open'],
    'missing colon' => ['{"result" 1}'],
    'empty' => [''],
]);

it('returns each array element as the exact text sent', function (): void {
    expect(RawJson::elements(' [ {"name":"a","x":{}} , 12345678901234567890,"]",[[]] ] '))
        ->toBe(['{"name":"a","x":{}}', '12345678901234567890', '"]"', '[[]]'])
        ->and(RawJson::elements('[]'))->toBe([]);
});

it('finds no elements in JSON that is not an array', function (string $json): void {
    expect(RawJson::elements($json))->toBeNull();
})->with(['{"a":1}', '[1,', '[1 2]', '"[]"', '']);

it('tells whether JSON is an object', function (string $json, bool $isObject): void {
    expect(RawJson::isObject($json))->toBe($isObject);
})->with([
    'object' => ['{"a":1}', true],
    'empty object' => ['{}', true],
    'array' => ['[]', false],
    'malformed' => ['{"a":', false],
]);
