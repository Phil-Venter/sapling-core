<?php

use Sapling\Http\Middleware\Cors;
use Sapling\Http\Request;
use Sapling\Http\Response;

test('Cors answers preflights before routing, for allowed origins only', function () {
	$app = scannedApp();
	$app->middleware(new Cors(['https://app.example']));
	$preflight = $app->handle(new Request('OPTIONS', '/echo/1', headers: [
		'Origin' => 'https://app.example',
		'Access-Control-Request-Method' => 'PUT',
	]));
	$allowed = $app->handle(new Request('GET', '/notes', headers: ['Origin' => 'https://app.example']));
	$other = $app->handle(new Request('GET', '/notes', headers: ['Origin' => 'https://evil.example']));

	expect([$preflight->status, $preflight->headers['Access-Control-Allow-Methods']])->toBe([204, 'PUT']);
	expect($allowed->headers['Access-Control-Allow-Origin'])->toBe('https://app.example');
	expect($other->headers)->not->toHaveKey('Access-Control-Allow-Origin');
});

test('Cors refuses credentials with a * origin, which would let any site read signed-in responses', function () {
	expect(fn() => new Cors(['*'], allowCredentials: true))
		->toThrow(InvalidArgumentException::class, 'cors_credentials needs cors_origins to list sites, not *');
});

test('Cors sends credentials for a listed origin, and adds to an existing Vary whatever its case', function (string $vary) {
	$cors = new Cors(['https://app.example'], allowCredentials: true);
	$next = fn(Request $request) => new Response('ok', 200, [$vary => 'Accept-Encoding']);

	$response = $cors(new Request('GET', '/notes', headers: ['Origin' => 'https://app.example']), $next);

	expect($response->headers)
		->toMatchArray([
			'Access-Control-Allow-Origin' => 'https://app.example',
			'Access-Control-Allow-Credentials' => 'true',
			'Vary' => 'Accept-Encoding, Origin',
		])
		->not->toHaveKey('vary');
})->with(['Vary', 'vary']);

test('Cors refuses origins that could never match, when the app starts', function (
	array $origins,
	bool $credentials,
	string $message,
) {
	expect(fn() => new Cors($origins, $credentials))->toThrow(InvalidArgumentException::class, $message);
})->with([
	'a trailing slash' => [
		['https://app.example/'],
		false,
		'needs scheme://host[:port] in lowercase, got https://app.example/',
	],
	'capitals' => [['https://App.example'], false, 'needs scheme://host[:port] in lowercase'],
	'no scheme' => [['app.example'], false, 'needs scheme://host[:port] in lowercase'],
]);

test('Cors varies on Origin whenever its answer depends on it, and never with *', function (
	array $origins,
	array $headers,
	?string $allowed,
	?string $vary,
) {
	$app = scannedApp();
	$app->middleware(new Cors($origins));

	$response = $app->handle(new Request('GET', '/notes', headers: $headers));

	expect($response->headers['Access-Control-Allow-Origin'] ?? null)->toBe($allowed);
	expect($response->headers['Vary'] ?? null)->toBe($vary);
})->with([
	'no Origin' => [['https://app.example'], [], null, 'Origin'],
	'an unlisted Origin' => [['https://app.example'], ['Origin' => 'https://evil.example'], null, 'Origin'],
	'a listed Origin' => [
		['https://app.example'],
		['Origin' => 'https://app.example'],
		'https://app.example',
		'Origin',
	],
	'*, where every site gets the same answer' => [['*'], ['Origin' => 'https://any.example'], '*', null],
]);

test('a preflight varies on what it echoes, and only names headers that were asked for', function () {
	$app = scannedApp();
	$app->middleware(new Cors(['https://app.example']));
	$ask = fn(array $headers) => $app->handle(new Request('OPTIONS', '/echo/1', headers: [
		'Origin' => 'https://app.example',
		'Access-Control-Request-Method' => 'PUT',
		...$headers,
	]));

	$plain = $ask([]);
	$withHeaders = $ask(['Access-Control-Request-Headers' => 'content-type, x-csrf-token']);
	$refused = $app->handle(new Request('OPTIONS', '/echo/1', headers: [
		'Origin' => 'https://evil.example',
		'Access-Control-Request-Method' => 'PUT',
	]));

	expect($plain->headers)
		->toMatchArray([
			'Access-Control-Max-Age' => '7200',
			'Vary' => 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers',
		])
		->not->toHaveKey('Access-Control-Allow-Headers');
	expect($withHeaders->headers['Access-Control-Allow-Headers'])->toBe('content-type, x-csrf-token');
	expect($refused->status)->toBe(403);
	expect($refused->headers['Vary'])->toBe('Origin, Access-Control-Request-Method, Access-Control-Request-Headers');
	expect($refused->headers)->not->toHaveKey('Access-Control-Allow-Origin');
});

test('an OPTIONS request that is not a preflight reaches the app', function () {
	$app = scannedApp();
	$app->middleware(new Cors(['https://app.example']));

	$response = $app->handle(new Request('OPTIONS', '/notes', headers: ['Origin' => 'https://app.example']));

	expect($response->status)->toBe(405);
});

test('an allowed site can read an HttpException, such as a 404, and other sites get it without CORS headers', function () {
	$app = scannedApp();
	$app->middleware(new Cors(['https://app.example']));

	$allowed = $app->handle(new Request('GET', '/nowhere', headers: ['Origin' => 'https://app.example']));
	$other = $app->handle(new Request('GET', '/nowhere', headers: ['Origin' => 'https://evil.example']));

	expect([$allowed->status, $allowed->body, $allowed->headers['Access-Control-Allow-Origin']])->toBe([
		404,
		'Not Found',
		'https://app.example',
	]);
	expect([$other->status, $other->body])->toBe([404, 'Not Found']);
	expect($other->headers)->not->toHaveKey('Access-Control-Allow-Origin');
});
