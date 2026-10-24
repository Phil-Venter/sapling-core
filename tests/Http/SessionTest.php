<?php

use Sapling\Http\Session;

beforeEach(function () {
	$this->server = $_SERVER;

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}

	session_id('');
	$_SESSION = [];
});

afterEach(function () {
	$_SERVER = $this->server;

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
});

function nextRequest(): Session
{
	session_write_close();
	$_SESSION = [];

	return new Session();
}

test('the cookie is HttpOnly, SameSite=Lax and Secure over HTTPS', function (array $server, bool $secure) {
	unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
	$_SERVER = [...$_SERVER, ...$server];
	new Session()->get('anything');

	expect(session_get_cookie_params())->httponly->toBeTrue()->samesite->toBe('Lax')->secure->toBe($secure);
})->with([
	'HTTPS' => [['HTTPS' => 'on'], true],
	'behind a TLS proxy' => [['HTTP_X_FORWARDED_PROTO' => 'https'], true],
	'HTTPS off' => [['HTTPS' => 'off', 'HTTP_X_FORWARDED_PROTO' => 'http'], false],
	'HTTPS empty' => [['HTTPS' => '', 'HTTP_X_FORWARDED_PROTO' => 'http'], false],
	'plain HTTP' => [['HTTP_X_FORWARDED_PROTO' => 'http'], false],
]);

test('the session runs in strict mode', function () {
	new Session()->get('anything');

	expect(ini_get('session.use_strict_mode'))->toBe('1');
});

test('get() and getFlash() take a path of keys, as Value::get() does', function () {
	$session = new Session();
	$session->set('user', ['id' => 7]);
	$session->setFlash('errors', ['email' => 'Required']);

	$next = nextRequest();

	expect($next->get('user', 'id')->int())->toBe(7);
	expect($next->getFlash('errors', 'email')->string())->toBe('Required');
});

test('flash data is readable during the next request only', function () {
	$first = new Session();
	$first->setFlash('notice', 'Saved.');
	expect($first->getFlash('notice')->raw)->toBeNull();

	$second = nextRequest();
	expect($second->getFlash('notice')->string())->toBe('Saved.');
	expect($second->getFlash('notice')->string())->toBe('Saved.');

	expect(nextRequest()->getFlash('notice')->string('gone'))->toBe('gone');
});

test('destroy() leaves a new, empty session for the next use', function () {
	$session = new Session();
	$session->set('user', 'ann');
	$before = session_id();

	$session->destroy();

	expect(session_status())->toBe(PHP_SESSION_NONE);
	expect($session->get('user')->raw)->toBeNull();
	expect(session_id())->not->toBe($before);
});

test('with a lifetime, every visit renews the cookie', function () {
	$first = httpRequest('GET', '/visit');
	$cookie = explode(';', headerValues($first, 'Set-Cookie')[0])[0];

	$second = httpRequest('GET', '/visit', ['Cookie' => $cookie]);

	expect($second['body'])->toBe('2');
	expect(headerValues($second, 'Set-Cookie')[0] ?? '')->toStartWith("{$cookie}; expires=");
});

test('a stale session id gets one new cookie, not a second renewal of it', function () {
	$response = httpRequest('GET', '/visit', ['Cookie' => 'PHPSESSID=stale0000000000000000000000000000']);

	expect($response['body'])->toBe('1');
	expect(headerValues($response, 'Set-Cookie'))->toHaveCount(1);
});
