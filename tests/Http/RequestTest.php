<?php

use Sapling\Http\Request;

beforeEach(function () {
	$this->globals = [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES];
});

afterEach(function () {
	[$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES] = $this->globals;
});

test('fromGlobals() reads method, normalized path, query, headers, cookies and files', function () {
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
	expect($request->param()->array())->toBe(['id' => '7']);
});

test('query() without a name gives the whole query string', function () {
	$request = new Request('GET', '/notes', query: ['page' => '2', 'tag' => 'php']);

	expect($request->query()->array())->toBe(['page' => '2', 'tag' => 'php']);
});

test('POST _method may switch only to PUT, PATCH or DELETE', function (
	string $method,
	string $override,
	string $expected,
) {
	$_SERVER['REQUEST_METHOD'] = $method;
	$_SERVER['REQUEST_URI'] = '/notes/7';
	$_POST = ['_method' => $override, 'body' => 'kept'];

	$request = Request::fromGlobals();

	expect($request->method)->toBe($expected);
	expect($request->data('body')->string())->toBe('kept');
})->with([
	['POST', 'delete',  'DELETE'],
	['POST', 'PUT',     'PUT'],
	['POST', 'patch',   'PATCH'],
	['POST', 'GET',     'POST'],
	['POST', 'OPTIONS', 'POST'],
	['GET',  'DELETE',  'GET'],
]);

test('data() reads a field as the type asked for, and a crafted array never reaches a string', function () {
	$request = new Request('POST', '/notes', input: ['body' => ['x'], 'tags' => ['a'], 'count' => '3']);

	expect($request->data('body')->string('fallback'))->toBe('fallback');
	expect($request->data('tags')->array())->toBe(['a']);
	expect($request->data('count')->int())->toBe(3);
	expect($request->data('missing')->raw)->toBeNull();
});

test('an array _method is ignored instead of raising a warning', function () {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_SERVER['REQUEST_URI'] = '/notes/7';
	$_POST = ['_method' => ['DELETE']];

	expect(Request::fromGlobals()->method)->toBe('POST');
});

test('a JSON body becomes input, alongside query, headers, cookies and params', function () {
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

test('data() is the whole body, and form fields PHP has already read win over it', function () {
	$json = new Request('PUT', '/x', headers: ['Content-Type' => 'application/json'], body: '[1,2]');
	$text = new Request('PUT', '/x', headers: ['Content-Type' => 'text/plain'], body: 'hello');
	$posted = new Request('POST', '/x', input: ['a' => '1'], headers: ['Content-Type' => 'multipart/form-data']);

	expect($json->data()->array())->toBe([1, 2]);
	expect($text->data()->string())->toBe('hello');
	expect($posted->data()->array())->toBe(['a' => '1']);
});

test('a broken body is a 400 when an action reads a field', function (string $type, string $body) {
	$response = httpRequest('PUT', '/echo/1', ['Content-Type' => $type], $body);

	expect([$response['status'], $response['body']])->toBe([400, 'The request body is invalid']);
})->with([
	'json' => ['application/json', '{not json'],
	'xml' => ['application/xml', '<not xml'],
]);

test('a body is never decoded by a request that reads no field', function () {
	$request = new Request('PUT', '/echo/1', headers: ['Content-Type' => 'application/json'], body: '{not json');

	expect($request->header('content-type')->string())->toBe('application/json');
	expect(fn() => $request->data('name'))->toThrow(Sapling\Http\HttpException::class, 'The request body is invalid');
});
