<?php

use Sapling\Http\Middleware\Csrf;
use Sapling\Http\Request;

test('Csrf refuses only writes a browser sent from another site', function (
	string $method,
	array $headers,
	array $cookies,
	int $status,
) {
	$app = scannedApp();
	$app->middleware(new Csrf(['https://front.example']));

	$request = new Request($method, '/notes', headers: $headers, cookies: $cookies);

	expect($app->handle($request)->status)->toBe($status);
})->with([
	'a read from another site' => ['GET', ['Sec-Fetch-Site' => 'cross-site'], [], 200],
	'same-origin' => ['POST', ['Sec-Fetch-Site' => 'same-origin', 'Origin' => 'https://app.example'], [], 302],
	'none, from the user typing the address' => ['POST', ['Sec-Fetch-Site' => 'none'], [], 302],
	'same-site, such as a subdomain' => ['POST', ['Sec-Fetch-Site' => 'same-site'], [], 403],
	'cross-site' => ['POST', ['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://evil.example'], [], 403],
	'an older browser, Origin matching Host' => [
		'POST',
		['Origin' => 'https://app.example', 'Host' => 'app.example'],
		[],
		302,
	],
	'an older browser, with a port' => [
		'POST',
		['Origin' => 'http://localhost:8080', 'Host' => 'localhost:8080'],
		[],
		302,
	],
	'an older browser, another site' => [
		'POST',
		['Origin' => 'https://evil.example', 'Host' => 'app.example'],
		[],
		403,
	],
	'an older browser, the same host on another port' => [
		'POST',
		['Origin' => 'https://app.example:8443', 'Host' => 'app.example'],
		[],
		403,
	],
	'an older browser, null from a sandboxed frame' => ['POST', ['Origin' => 'null', 'Host' => 'app.example'], [], 403],
	'neither header, from a script or a server' => ['POST', ['Authorization' => 'Bearer abc'], [], 302],
	'neither header but the session cookie, from a browser too old to send them' => [
		'POST',
		[],
		[session_name() => 'abc123'],
		403,
	],
	'a trusted site' => ['POST', ['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://front.example'], [], 302],
]);

test('a refused write is a 403 that says why', function () {
	$app = scannedApp();
	$app->middleware(Csrf::class);

	$response = $app->handle(new Request('POST', '/notes', headers: ['Sec-Fetch-Site' => 'cross-site']));

	expect([$response->status, $response->body])->toBe([403, 'Cross-site request refused']);
});

test('a refused write never starts the session', function () {
	$app = scannedApp();
	$app->middleware(Csrf::class);

	$app->handle(new Request('POST', '/notes', headers: ['Sec-Fetch-Site' => 'cross-site']));

	expect(session_status())->not->toBe(PHP_SESSION_ACTIVE);
});
