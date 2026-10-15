<?php

use Sapling\Data;
use Sapling\Http\HttpClient;

function serverUrl(string $path): string
{
	return 'http://' . httpServer() . $path;
}

beforeEach(fn() => $this->client = new HttpClient());

test('request() returns status, lowercased headers and body', function () {
	$response = $this->client->request('GET', serverUrl('/notes'));

	expect($response->status)->toBe(200);
	expect($response->headers['content-type'])->toBe('text/plain; charset=utf-8');
	expect($response->body)->toBe('notes');
});

test('a body goes as it is with the Content-Type you pass, and data() decodes the reply', function () {
	$headers = ['X-Test' => 'header value', 'Content-Type' => 'application/json'];
	$echo = $this->client
		->request('PUT', serverUrl('/echo/7?q=search'), Data::toJson(['name' => 'Ann']), $headers)
		->data()
		->array();

	expect($echo)->toMatchArray([
		'method' => 'PUT',
		'id' => '7',
		'name' => 'Ann',
		'q' => 'search',
		'header' => 'header value',
		'content_type' => 'application/json',
	]);
});

test('an array body uploads multipart fields and files', function () {
	$fields = ['name' => 'Ann', 'file' => new CURLStringFile('hello', 'hello.txt', 'text/plain')];
	$echo = $this->client->request('POST', serverUrl('/echo-upload'), $fields)->data()->array();

	expect($echo)->toBe(['name' => 'Ann', 'file' => 'hello.txt', 'contents' => 'hello']);
});

test('a GET follows redirects and keeps only the final headers; a POST gets the redirect back', function () {
	$followed = $this->client->request('GET', serverUrl('/old-address'));
	$returned = $this->client->request('POST', serverUrl('/notes'), Data::toForm(['body' => 'x']));

	expect([$followed->status, $followed->body])->toBe([200, 'notes']);
	expect($followed->headers)->not->toHaveKey('location');
	expect([$returned->status, $returned->headers['location']])->toBe([302, '/notes']);
});

test('HEAD returns headers without waiting for a body', function () {
	$response = $this->client->request('HEAD', serverUrl('/notes'));

	expect([$response->status, $response->body])->toBe([200, '']);
	expect($response->headers['content-type'])->toBe('text/plain; charset=utf-8');
});

test('repeated headers come back as a list', function () {
	expect($this->client->request('GET', serverUrl('/cookies'))->headers['set-cookie'])->toBe([
		'a=1; Path=/',
		'b=2; Path=/',
	]);
});

test('a 404 is a response, but a failed connection throws', function () {
	expect($this->client->request('GET', serverUrl('/nowhere'))->status)->toBe(404);
	expect(fn() => new HttpClient(timeout: 2)->request('GET', 'http://127.0.0.1:1/'))
		->toThrow(RuntimeException::class, 'GET http://127.0.0.1:1/ failed:');
});
