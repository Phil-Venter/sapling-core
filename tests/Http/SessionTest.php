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

test('the cookie is HttpOnly, SameSite=Lax and Secure over HTTPS, with strict mode on', function () {
	$_SERVER['HTTPS'] = 'on';
	new Session()->get('anything');

	expect(session_get_cookie_params())->httponly->toBeTrue()->secure->toBeTrue()->samesite->toBe('Lax');
	expect(ini_get('session.use_strict_mode'))->toBe('1');
});

test('the cookie is Secure behind a TLS proxy that sends X-Forwarded-Proto', function () {
	unset($_SERVER['HTTPS']);
	$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
	new Session()->get('anything');

	expect(session_get_cookie_params()['secure'])->toBeTrue();
});

test('the cookie is not Secure over plain HTTP', function (?string $https) {
	$_SERVER['HTTPS'] = $https;
	$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
	new Session()->get('anything');

	expect(session_get_cookie_params()['secure'])->toBeFalse();
})->with(['off' => 'off', 'empty' => '', 'missing' => null]);

test('flash data is readable during the next request only', function () {
	$first = new Session();
	$first->setFlash('notice', 'Saved.');
	expect($first->getFlash('notice')->raw)->toBeNull();

	$second = nextRequest();
	expect($second->getFlash('notice')->string())->toBe('Saved.');
	expect($second->getFlash('notice')->string())->toBe('Saved.');

	expect(nextRequest()->getFlash('notice')->string('gone'))->toBe('gone');
});

test('regenerate() changes the id and keeps the data', function () {
	$session = new Session();
	$session->set('user', 'ann');
	$before = session_id();

	$session->regenerate();

	expect(session_id())->not->toBe($before);
	expect($session->get('user')->string())->toBe('ann');
	expect(nextRequest()->get('user')->string())->toBe('ann');
});

test('destroy() forgets everything and the next use starts a fresh session', function () {
	$session = new Session();
	$session->set('user', 'ann');
	$before = session_id();

	$session->destroy();

	expect(session_status())->toBe(PHP_SESSION_NONE);
	expect($session->get('user')->raw)->toBeNull();
	expect(session_id())->not->toBe($before);
});
