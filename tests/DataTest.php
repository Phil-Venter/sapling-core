<?php

use Sapling\Data;

test('JSON round-trips with slashes and Unicode as they are', function () {
	$data = ['name' => 'Ann', 'tags' => ['a', 'b'], 'url' => 'https://example.com/ä'];

	expect(Data::toJson($data))->toBe('{"name":"Ann","tags":["a","b"],"url":"https://example.com/ä"}');
	expect(Data::fromJson(Data::toJson($data)))->toBe($data);
});

test('XML round-trips', function () {
	$data = [
		'user' => [
			'@id' => '7',
			'name' => 'Ann & Bo',
			'tags' => ['a', 'b'],
			'price' => ['@currency' => 'EUR', '#text' => '5'],
		],
	];
	$xml = Data::toXml($data, 'request');

	expect($xml)->toContain('<request><user id="7"><name>Ann &amp; Bo</name><tags>a</tags><tags>b</tags><price currency="EUR">5</price></user></request>');
	expect(Data::fromXml($xml))->toBe($data);
});

test('XML gives every value back as a string', function () {
	expect(Data::fromXml(Data::toXml(['count' => 3, 'active' => true, 'note' => null])))->toBe([
		'count' => '3',
		'active' => 'true',
		'note' => '',
	]);
});

test('namespaced XML keeps its prefixes', function () {
	$soap =
		'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
		. '<GetPriceResponse><Price>1.90</Price></GetPriceResponse></soap:Body></soap:Envelope>';

	expect(Data::fromXml($soap))->toBe(['soap:Body' => ['GetPriceResponse' => ['Price' => '1.90']]]);
});

test('XML reads CDATA as text', function () {
	expect(Data::fromXml('<item><title><![CDATA[Fish & Chips]]></title></item>'))->toBe(['title' => 'Fish & Chips']);
});

test('XML never fetches an external entity', function () {
	$xxe = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x><y>&e;</y></x>';

	expect(Data::fromXml($xxe))->toBe(['y' => '']);
});

test('broken JSON and XML throw', function () {
	expect(fn() => Data::fromJson('{nope'))->toThrow(JsonException::class);
	expect(fn() => Data::fromXml('<nope'))->toThrow(RuntimeException::class, 'Invalid XML:');
});

test('decode() picks the format by Content-Type', function (string $type, string $body, mixed $data) {
	expect(Data::decode($body, $type))->toBe($data);
})->with([
	'json' => ['application/json; charset=utf-8', '{"a":1}', ['a' => 1]],
	'json suffix' => ['application/problem+json', '{"title":"x"}', ['title' => 'x']],
	'xml' => ['text/xml', '<r><a>1</a></r>', ['a' => '1']],
	'xml suffix' => ['application/atom+xml', '<feed><title>t</title></feed>', ['title' => 't']],
	'form' => ['application/x-www-form-urlencoded', 'a=1&b%5B%5D=2', ['a' => '1', 'b' => ['2']]],
	'empty' => ['application/json', '', null],
	'text' => ['text/plain', 'hello', 'hello'],
	'no type' => ['', 'raw', 'raw'],
]);

test('toXml() always writes one root element', function () {
	expect(Data::toXml([]))->toContain('<root/>');
	expect(Data::toXml(['tags' => []]))->toContain('<root><tags/></root>');
});

test('toXml() refuses a list', function () {
	expect(fn() => Data::toXml(['a', 'b']))->toThrow(InvalidArgumentException::class, 'was given a list');
});
