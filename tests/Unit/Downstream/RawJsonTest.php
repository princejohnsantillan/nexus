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

it('replaces one member\'s value and keeps the rest of the text exactly as sent', function (string $json, string $key, string $value, string $replaced): void {
    expect(RawJson::withMember($json, $key, $value))->toBe($replaced);
})->with([
    'first member' => ['{"name":"search","inputSchema":{"type":"object","properties":{}}}', 'name', '"wiki__search"', '{"name":"wiki__search","inputSchema":{"type":"object","properties":{}}}'],
    'last member' => ['{"a":1,"name":"x"}', 'name', '"y"', '{"a":1,"name":"y"}'],
    'long numbers stay' => ['{"name":"n","max":18446744073709551615,"big":1e400}', 'name', '"m"', '{"name":"m","max":18446744073709551615,"big":1e400}'],
    'whitespace stays' => ["{ \"name\" : \"x\" ,\n \"a\" : {} }", 'name', '"y"', "{ \"name\" : \"y\" ,\n \"a\" : {} }"],
    'nested members stay' => ['{"annotations":{"name":"inner"},"name":"outer"}', 'name', '"new"', '{"annotations":{"name":"inner"},"name":"new"}'],
    'every duplicate' => ['{"name":"a","x":1,"name":"b"}', 'name', '"c"', '{"name":"c","x":1,"name":"c"}'],
    'escaped key' => ['{"name":"x"}', 'name', '"y"', '{"name":"y"}'],
]);

it('replaces nothing in JSON that is not an object or lacks the member', function (string $json): void {
    expect(RawJson::withMember($json, 'name', '"y"'))->toBeNull();
})->with([
    'missing' => ['{"title":"x"}'],
    'empty object' => ['{}'],
    'array' => ['[{"name":"x"}]'],
    'unterminated' => ['{"name":"x",'],
    'empty' => [''],
]);

it('sets a member, adding it at the end when the object lacks it and keeping the rest as sent', function (string $json, string $key, string $value, string $set): void {
    expect(RawJson::put($json, $key, $value))->toBe($set);
})->with([
    'replaced' => ['{"resultType":"incomplete","content":[]}', 'resultType', '"complete"', '{"resultType":"complete","content":[]}'],
    'added at the end' => ['{"content":[],"structuredContent":{}}', 'resultType', '"complete"', '{"content":[],"structuredContent":{},"resultType":"complete"}'],
    'added to an empty object' => ['{ }', 'a', '1', '{ "a":1}'],
    'key with slashes' => ['{"x":{}}', 'io.modelcontextprotocol/serverInfo', '{"name":"n"}', '{"x":{},"io.modelcontextprotocol/serverInfo":{"name":"n"}}'],
    'long numbers stay' => ['{"big":1e400,"max":18446744073709551615}', 'n', '2', '{"big":1e400,"max":18446744073709551615,"n":2}'],
    'whitespace stays' => ["{ \"a\" : {} \n}", 'b', 'true', "{ \"a\" : {},\"b\":true \n}"],
    'nested member is not the member' => ['{"_meta":{"resultType":"x"}}', 'resultType', '"complete"', '{"_meta":{"resultType":"x"},"resultType":"complete"}'],
]);

it('sets nothing in JSON that is not an object', function (string $json): void {
    expect(RawJson::put($json, 'a', '1'))->toBeNull();
})->with([
    'array' => ['[{"a":1}]'],
    'unterminated' => ['{"a":1,'],
    'empty' => [''],
]);
