<?php

use Sapling\Http\HttpException;
use Sapling\Http\Request;

beforeEach(function () {
	$this->globals = [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES];
});

afterEach(function () {
	[$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES] = $this->globals;
});

test('fromGlobals() reads the request from PHP\'s globals', function () {
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_SERVER['REQUEST_URI'] = '//notes//7/?page=2';
	$_SERVER['HTTP_X_REQUEST_ID'] = 'abc';
	$_SERVER['CONTENT_TYPE'] = 'text/plain';
	$_SERVER['CONTENT_LENGTH'] = '0';
	$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
	$_GET = ['page' => '2'];
	$_COOKIE = ['theme' => 'dark'];
	$_FILES = ['upload' => ['name' => 'a.txt']];

	$request = Request::fromGlobals();

	expect($request->method)->toBe('GET');
	expect($request->path)->toBe('/notes/7');
	expect($request->query('page')->int())->toBe(2);
	expect($request->header('X-Request-Id')->string())->toBe('abc');
	expect($request->header('content-type')->string())->toBe('text/plain');
	expect($request->header('content-length')->string())->toBe('0');
	expect($request->cookie('theme')->string())->toBe('dark');
	expect($request->files)->toBe(['upload' => ['name' => 'a.txt']]);
	expect($request->body)->toBe('');
	expect($request->ip)->toBe('203.0.113.7');
});

test('behind a trusted proxy, the ip is the visitor\'s from X-Forwarded-For', function (
	array $trusted,
	string $expected,
) {
	$_SERVER['REMOTE_ADDR'] = '10.0.0.2';
	$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 203.0.113.9, 10.0.0.1';

	expect(Request::fromGlobals($trusted)->ip)->toBe($expected);
})->with([
	'no trusted proxies' => [[], '10.0.0.2'],
	'an untrusted proxy' => [['10.0.0.9'], '10.0.0.2'],
	'trusted proxies, closest first' => [['10.0.0.2', '10.0.0.1'], '203.0.113.9'],
	'any proxy, trusting only the one that connected' => [['*'], '10.0.0.1'],
	'any proxy, and the listed ones' => [['*', '10.0.0.1'], '203.0.113.9'],
]);

test('the readers take a path of keys, as Value::get() does', function () {
	$request = new Request(
		'GET',
		'/notes',
		query: ['filter' => ['status' => 'open']],
		input: ['address' => ['city' => 'Cape Town']],
		cookies: ['prefs' => ['theme' => 'dark']],
	);
	$request->params = ['id' => '7'];

	expect($request->query('filter', 'status')->string())->toBe('open');
	expect($request->data('address', 'city')->string())->toBe('Cape Town');
	expect($request->cookie('prefs', 'theme')->string())->toBe('dark');
	expect($request->param()->rawArray())->toBe(['id' => '7']);
	expect($request->query()->rawArray())->toBe(['filter' => ['status' => 'open']]);
});

test('POST _method can switch only to PUT, PATCH or DELETE', function (
	string $method,
	string|array $override,
	string $expected,
) {
	$_SERVER['REQUEST_METHOD'] = $method;
	$_SERVER['REQUEST_URI'] = '/notes/7';
	$_POST = ['_method' => $override, 'body' => 'kept'];

	$request = Request::fromGlobals();

	expect($request->method)->toBe($expected);
	expect($request->data('body')->string())->toBe('kept');
})->with([
	['POST', 'delete', 'DELETE'],
	['POST', 'PUT', 'PUT'],
	['POST', 'patch', 'PATCH'],
	['POST', 'GET', 'POST'],
	['POST', 'OPTIONS', 'POST'],
	['GET', 'DELETE', 'GET'],
	'an array, ignored without a warning' => ['POST', ['DELETE'], 'POST'],
]);

test('a crafted array never reaches a string field', function () {
	$request = new Request('POST', '/notes', input: ['body' => ['x']]);

	expect($request->data('body')->string('fallback'))->toBe('fallback');
});

test('a JSON request through php -S reaches the action intact', function () {
	$body = '{"name":"Ann"}';
	$response = httpRequest(
		'PUT',
		'/echo/7/?q=search',
		[
			'Content-Type' => 'application/json; charset=utf-8',
			'X-Test' => 'header value',
			'Cookie' => 'c=cookie value',
		],
		$body,
	);

	expect(json_decode($response['body'], true))->toMatchArray([
		'method' => 'PUT',
		'path' => '/echo/7',
		'id' => '7',
		'name' => 'Ann',
		'q' => 'search',
		'header' => 'header value',
		'cookie' => 'cookie value',
		'body' => $body,
		'ip' => '127.0.0.1',
	]);
});

test('data() reads JSON, plain text and posted form fields', function () {
	$json = new Request('PUT', '/x', headers: ['Content-Type' => 'application/json'], body: '[1,2]');
	$text = new Request('PUT', '/x', headers: ['Content-Type' => 'text/plain'], body: 'hello');
	$posted = new Request('POST', '/x', input: ['a' => '1'], headers: ['Content-Type' => 'multipart/form-data']);

	expect($json->data()->rawArray())->toBe([1, 2]);
	expect($text->data()->string())->toBe('hello');
	expect($posted->data()->rawArray())->toBe(['a' => '1']);
});

test('a broken body is a 400 when an action reads a field', function (string $type, string $body) {
	$response = httpRequest('PUT', '/echo/1', ['Content-Type' => $type], $body);

	expect([$response['status'], $response['body']])->toBe([400, 'The request body is invalid']);
})->with([
	'json' => ['application/json', '{not json'],
	'xml' => ['application/xml', '<not xml'],
]);

test('a broken body throws only once a field is read', function () {
	$request = new Request('PUT', '/echo/1', headers: ['Content-Type' => 'application/json'], body: '{not json');

	expect($request->header('content-type')->string())->toBe('application/json');
	expect(fn() => $request->data('name'))->toThrow(HttpException::class, 'The request body is invalid');
});
