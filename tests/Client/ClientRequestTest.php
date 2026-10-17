<?php

use Sapling\Client\ClientRequest;
use Sapling\Data;

test('json(), form(), xml() and text() encode the body and set its Content-Type', function (
	ClientRequest $request,
	string $body,
	string $type,
) {
	expect($request->body)->toBe($body);
	expect($request->headers['content-type'])->toBe($type);
})->with([
	'json' => [ClientRequest::json('PUT', '/', ['name' => 'Ann']), '{"name":"Ann"}', 'application/json'],
	'form' => [ClientRequest::form('PUT', '/', ['name' => 'Ann']), 'name=Ann', 'application/x-www-form-urlencoded'],
	'xml' => [
		ClientRequest::xml('PUT', '/', ['name' => 'Ann'], 'note'),
		Data::toXml(['name' => 'Ann'], 'note'),
		'application/xml',
	],
	'text' => [ClientRequest::text('PUT', '/', 'Ann'), 'Ann', 'text/plain; charset=utf-8'],
]);

test('json() without data has no body or Content-Type, while [] and {} are sent as they are', function () {
	$none = ClientRequest::json('PUT', '/');

	expect([$none->body, $none->headers])->toBe(['', []]);
	expect(ClientRequest::json('PUT', '/', [])->body)->toBe('[]');
	expect(ClientRequest::json('PUT', '/', new stdClass())->body)->toBe('{}');
});

test('header names are lowercased, so one you pass replaces the Content-Type whatever its case', function () {
	$request = ClientRequest::json('PUT', '/', ['name' => 'Ann'], ['Content-Type' => 'application/merge-patch+json']);

	expect($request->headers)->toBe(['content-type' => 'application/merge-patch+json']);
});
