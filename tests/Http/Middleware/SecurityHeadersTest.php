<?php

use Sapling\Http\Middleware\SecurityHeaders;
use Sapling\Http\Request;
use Sapling\Http\Response;

beforeEach(function () {
	$this->app = scannedApp();
});

test('directives passed to SecurityHeaders replace the defaults, and the rest stay', function () {
	$policy = ['script-src' => "'self' https://unpkg.com", 'img-src' => "'self'", 'upgrade-insecure-requests' => ''];
	$this->app->container->set(SecurityHeaders::class, new SecurityHeaders($policy));
	$this->app->middleware(SecurityHeaders::class);

	$headers = $this->app->handle(new Request('GET', '/notes'))->headers;

	expect($headers['Content-Security-Policy'])
		->toBe("default-src 'self'; style-src 'self' https:; img-src 'self'; font-src 'self' https: data:; base-uri 'none'; "
		. "form-action 'self'; frame-ancestors 'none'; script-src 'self' https://unpkg.com; upgrade-insecure-requests");
	expect($headers['X-Frame-Options'])->toBe('DENY');
});

test('a header the action set itself is kept, so one page can have its own policy', function () {
	$this->app->router->add('GET', '/own-policy', fn(): Response => Response::text('embed')->withHeader(
		'Content-Security-Policy',
		"frame-ancestors 'self'",
	));
	$this->app->middleware(SecurityHeaders::class);

	$headers = $this->app->handle(new Request('GET', '/own-policy'))->headers;

	expect($headers['Content-Security-Policy'])->toBe("frame-ancestors 'self'");
	expect($headers['X-Frame-Options'])->toBe('DENY');
});
