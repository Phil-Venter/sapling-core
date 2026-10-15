<?php

use Sapling\Http\Middleware\Csrf;
use Sapling\Http\Request;

function post(array $headers, ?Csrf $csrf = null): int
{
	$app = scannedApp();
	$app->middleware($csrf ?? new Csrf());

	return $app->handle(new Request('POST', '/notes', headers: $headers))->status;
}

test('Csrf lets reads through from anywhere', function () {
	$app = scannedApp();
	$app->middleware(Csrf::class);

	$response = $app->handle(new Request('GET', '/notes', headers: ['Sec-Fetch-Site' => 'cross-site']));

	expect([$response->status, $response->body])->toBe([200, 'notes']);
});

test('Csrf trusts Sec-Fetch-Site: only the same origin, or the user typing the address, may write', function (
	string $site,
	int $status,
) {
	expect(post(['Sec-Fetch-Site' => $site, 'Origin' => 'https://app.example', 'Host' => 'app.example']))
		->toBe($status);
})->with([
	'same-origin' => ['same-origin', 302],
	'none' => ['none', 302],
	'same-site, such as a subdomain' => ['same-site', 403],
	'cross-site' => ['cross-site', 403],
]);

test('without Sec-Fetch-Site, an older browser must send an Origin that matches Host', function (
	array $headers,
	int $status,
) {
	expect(post($headers))->toBe($status);
})->with([
	'matching' => [['Origin' => 'https://app.example', 'Host' => 'app.example'], 302],
	'matching, with a port' => [['Origin' => 'http://localhost:8080', 'Host' => 'localhost:8080'], 302],
	'another site' => [['Origin' => 'https://evil.example', 'Host' => 'app.example'], 403],
	'the same host on another port' => [['Origin' => 'https://app.example:8443', 'Host' => 'app.example'], 403],
	'null, from a sandboxed frame' => [['Origin' => 'null', 'Host' => 'app.example'], 403],
]);

test('a write with neither header is from a script or a server, not a browser, so it passes', function () {
	expect(post(['Authorization' => 'Bearer abc']))->toBe(302);
});

test('a write with neither header but the session cookie is refused, as from a browser too old to send them', function () {
	$app = scannedApp();
	$app->middleware(Csrf::class);

	$response = $app->handle(new Request('POST', '/notes', cookies: [session_name() => 'abc123']));

	expect($response->status)->toBe(403);
});

test('a trusted origin may write from another site', function () {
	$csrf = new Csrf(['https://front.example']);

	expect(post(['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://front.example'], $csrf))->toBe(302);
	expect(post(['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://evil.example'], $csrf))->toBe(403);
});

test('a refused write explains itself, and checking never starts a session', function () {
	$app = scannedApp();
	$app->middleware(Csrf::class);

	$response = $app->handle(new Request('POST', '/notes', headers: ['Sec-Fetch-Site' => 'cross-site']));

	expect([$response->status, $response->body])->toBe([403, 'Cross-site request refused']);
	expect(session_status())->not->toBe(PHP_SESSION_ACTIVE);
});
