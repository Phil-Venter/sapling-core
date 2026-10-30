<?php

use Sapling\Http\Response;

test('withHeader() returns a copy', function () {
	$original = Response::text('hi');

	$changed = $original->withHeader('X-Note', 'yes');

	expect($original->headers)->toBe(['Content-Type' => 'text/plain; charset=utf-8']);
	expect($changed->headers['X-Note'])->toBe('yes');
});

test('withHeader() replaces a header whatever the case of its name', function () {
	$response = Response::text('hi')->withHeader('content-type', 'text/csv');

	expect($response->headers)->toBe(['content-type' => 'text/csv']);
});

test('send() skips the body for 1xx, 204 and 304', function (int $status) {
	ob_start();
	new Response('should not appear', $status)->send();

	expect(ob_get_clean())->toBe('');
})->with([101, 204, 304]);

test('send(body: false) never reads a file', function () {
	ob_start();
	Response::file(FIXTURES . '/files/hello.txt')->send(body: false);

	expect(ob_get_clean())->toBe('');
});

test('HEAD on a GET route sends the headers and no body', function () {
	$response = httpRequest('HEAD', '/notes');

	expect($response['status'])->toBe(200);
	expect(headerValues($response, 'Content-Type'))->toBe(['text/plain; charset=utf-8']);
	expect($response['body'])->toBe('');
});

test('a Location header keeps the status it came with', function () {
	$response = httpRequest('POST', '/jobs');

	expect($response['status'])->toBe(202);
	expect(headerValues($response, 'Location'))->toBe(['/jobs/1']);
});

test('several Set-Cookie values each get their own line', function () {
	$response = httpRequest('GET', '/cookies');

	expect(headerValues($response, 'Set-Cookie'))->toBe(['a=1; Path=/', 'b=2; Path=/']);
});

test('file() sends the file as a download, with a name any browser can use', function () {
	$response = httpRequest('GET', '/download');

	expect($response['status'])->toBe(200);
	expect($response['body'])->toBe("hello from a file\n");
	expect(headerValues($response, 'Content-Length'))->toBe(['18']);
	expect(headerValues($response, 'Content-Disposition'))->toBe([
		"attachment; filename=\"h_llo _notes_.txt\"; filename*=UTF-8''h%C3%A9llo%20%22notes%22.txt",
	]);
});

test('file() refuses a file that does not exist', function () {
	expect(fn() => Response::file('/no/such/file.txt'))
		->toThrow(RuntimeException::class, 'File not found: /no/such/file.txt');
});

test('data() reads one field, or the whole body without a name', function () {
	$response = Response::json(['title' => 'Notes', 'count' => 2]);

	expect($response->data('title')->string())->toBe('Notes');
	expect($response->data()->rawArray())->toBe(['title' => 'Notes', 'count' => 2]);
});

test('header() finds a header whatever the case of its name', function () {
	$response = new Response('', 200, ['content-type' => 'text/plain', 'set-cookie' => ['a=1', 'b=2']]);

	expect($response->header('Content-Type')->string())->toBe('text/plain');
	expect($response->header('Set-Cookie')->rawArray())->toBe(['a=1', 'b=2']);
	expect($response->header('X-Missing')->raw)->toBeNull();
});
