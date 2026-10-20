<?php

use Sapling\Http\Middleware\SecurityHeaders;
use Sapling\Http\Request;
use Sapling\Http\Response;

beforeEach(function () {
	$this->app = scannedApp();
});

test('a directive passed in replaces only its default', function () {
	$policy = ['script-src' => "'self' https://unpkg.com", 'img-src' => "'self'", 'upgrade-insecure-requests' => ''];
	$this->app->middleware(new SecurityHeaders($policy));

	$headers = $this->app->handle(new Request('GET', '/notes'))->headers;

	expect($headers['Content-Security-Policy'])
		->toBe("default-src 'self'; style-src 'self' https: 'unsafe-inline'; img-src 'self'; font-src 'self' https: data:; "
		. "base-uri 'none'; form-action 'self'; frame-ancestors 'none'; script-src 'self' https://unpkg.com; "
		. 'upgrade-insecure-requests');
	expect($headers['X-Frame-Options'])->toBe('DENY');
});

test('a header the action set is kept', function () {
	$this->app->router->add('GET', '/own-policy', fn(): Response => Response::text('embed')->withHeader(
		'Content-Security-Policy',
		"frame-ancestors 'self'",
	));
	$this->app->middleware(SecurityHeaders::class);

	$headers = $this->app->handle(new Request('GET', '/own-policy'))->headers;

	expect($headers['Content-Security-Policy'])->toBe("frame-ancestors 'self'");
	expect($headers['X-Frame-Options'])->toBe('DENY');
});
