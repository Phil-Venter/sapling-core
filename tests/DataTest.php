<?php

use Sapling\Data;

test('JSON round-trips with slashes and Unicode as they are', function () {
	$data = ['name' => 'Ann', 'tags' => ['a', 'b'], 'url' => 'https://example.com/ä'];

	expect(Data::toJson($data))->toBe('{"name":"Ann","tags":["a","b"],"url":"https://example.com/ä"}');
	expect(Data::fromJson(Data::toJson($data)))->toBe($data);
});

test('broken JSON throws', function () {
	expect(fn() => Data::fromJson('{nope'))->toThrow(JsonException::class);
});

test('decode() picks the format by Content-Type', function (string $type, string $body, mixed $data) {
	expect(Data::decode($body, $type))->toBe($data);
})->with([
	'json' => ['application/json; charset=utf-8', '{"a":1}', ['a' => 1]],
	'json suffix' => ['application/problem+json', '{"title":"x"}', ['title' => 'x']],
	'form' => ['application/x-www-form-urlencoded', 'a=1&b%5B%5D=2', ['a' => '1', 'b' => ['2']]],
	'empty' => ['application/json', '', null],
	'text' => ['text/plain', 'hello', 'hello'],
	'xml' => ['application/xml', '<r><a>1</a></r>', '<r><a>1</a></r>'],
	'no type' => ['', 'raw', 'raw'],
]);
