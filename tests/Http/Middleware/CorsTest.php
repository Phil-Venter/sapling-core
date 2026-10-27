<?php

use Sapling\Http\Middleware\Cors;
use Sapling\Http\Request;
use Sapling\Http\Response;

test('Cors answers a preflight before routing', function () {
	$app = scannedApp();
	$app->middleware(new Cors(['https://app.example']));

	$preflight = $app->handle(new Request('OPTIONS', '/echo/1', headers: [
		'Origin' => 'https://app.example',
		'Access-Control-Request-Method' => 'PUT',
	]));

	expect([$preflight->status, $preflight->headers['Access-Control-Allow-Methods']])->toBe([204, 'PUT']);
});

test('Cors allows credentials for a listed origin', function () {
	$cors = new Cors(['https://app.example'], allowCredentials: true);
	$next = fn(Request $request) => new Response('ok');

	$response = $cors(new Request('GET', '/notes', headers: ['Origin' => 'https://app.example']), $next);

	expect($response->headers)->toMatchArray([
		'Access-Control-Allow-Origin' => 'https://app.example',
		'Access-Control-Allow-Credentials' => 'true',
	]);
});

test('Cors adds Origin to an existing Vary, whatever its case', function (string $vary) {
	$cors = new Cors(['https://app.example']);
	$next = fn(Request $request) => new Response('ok', 200, [$vary => 'Accept-Encoding']);

	$response = $cors(new Request('GET', '/notes', headers: ['Origin' => 'https://app.example']), $next);

	expect($response->headers['Vary'])->toBe('Accept-Encoding, Origin');
	expect($response->headers)->not->toHaveKey('vary');
})->with(['Vary', 'vary']);

test('Cors refuses origins that could never match', function (array $origins, bool $credentials, string $message) {
	expect(fn() => new Cors($origins, $credentials))->toThrow(InvalidArgumentException::class, $message);
})->with([
	'a trailing slash' => [
		['https://app.example/'],
		false,
		'needs scheme://host[:port] in lowercase, got https://app.example/',
	],
	'capitals' => [['https://App.example'], false, 'needs scheme://host[:port] in lowercase'],
	'no scheme' => [['app.example'], false, 'needs scheme://host[:port] in lowercase'],
	'* with credentials' => [['*'], true, 'Cannot use cors_credentials with cors_origins *'],
]);

test('Cors varies on Origin unless it allows *', function (
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
	'*, without an Origin' => [['*'], [], '*', null],
]);

test('a preflight varies on the request headers it echoes', function () {
	$app = scannedApp();
	$app->middleware(new Cors(['https://app.example']));
	$ask = fn(array $headers) => $app->handle(new Request('OPTIONS', '/echo/1', headers: [
		'Origin' => 'https://app.example',
		'Access-Control-Request-Method' => 'PUT',
		...$headers,
	]));

	$plain = $ask([]);
	$withHeaders = $ask(['Access-Control-Request-Headers' => 'content-type, x-csrf-token']);

	expect($plain->headers)->toMatchArray([
		'Access-Control-Max-Age' => '7200',
		'Vary' => 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers',
	]);
	expect($plain->headers)->not->toHaveKey('Access-Control-Allow-Headers');
	expect($withHeaders->headers['Access-Control-Allow-Headers'])->toBe('content-type, x-csrf-token');
});

test('a preflight from an unlisted site is a 403', function () {
	$app = scannedApp();
	$app->middleware(new Cors(['https://app.example']));

	$refused = $app->handle(new Request('OPTIONS', '/echo/1', headers: [
		'Origin' => 'https://evil.example',
		'Access-Control-Request-Method' => 'PUT',
	]));

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

test('an HttpException gets CORS headers for allowed sites only', function () {
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
