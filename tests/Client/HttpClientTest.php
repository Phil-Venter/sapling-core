<?php

use Sapling\Client\ClientRequest;
use Sapling\Client\HttpClient;
use Sapling\Data;

function serverUrl(string $path): string
{
	return 'http://' . httpServer() . $path;
}

beforeEach(fn() => $this->client = new HttpClient());

test('send() returns status, lowercased headers and body', function () {
	$response = $this->client->send(new ClientRequest('GET', serverUrl('/notes')));

	expect($response->status)->toBe(200);
	expect($response->headers['content-type'])->toBe('text/plain; charset=utf-8');
	expect($response->body)->toBe('notes');
});

test('a string body goes as it is with the Content-Type you pass, and data() decodes the reply', function () {
	$headers = ['X-Test' => 'header value', 'Content-Type' => 'application/json'];
	$request = new ClientRequest('PUT', serverUrl('/echo/7?q=search'), Data::toJson(['name' => 'Ann']), $headers);
	$echo = $this->client->send($request)->data()->array();

	expect($echo)->toMatchArray([
		'method' => 'PUT',
		'id' => '7',
		'name' => 'Ann',
		'q' => 'search',
		'header' => 'header value',
		'content_type' => 'application/json',
	]);
});

test('a GET follows redirects and keeps only the final headers; a POST gets the redirect back', function () {
	$followed = $this->client->send(new ClientRequest('GET', serverUrl('/old-address')));
	$returned = $this->client->send(ClientRequest::form('POST', serverUrl('/notes'), ['body' => 'x']));

	expect([$followed->status, $followed->body])->toBe([200, 'notes']);
	expect($followed->headers)->not->toHaveKey('location');
	expect([$returned->status, $returned->headers['location']])->toBe([302, '/notes']);
});

test('HEAD returns headers without waiting for a body', function () {
	$response = $this->client->send(new ClientRequest('HEAD', serverUrl('/notes')));

	expect([$response->status, $response->body])->toBe([200, '']);
	expect($response->headers['content-type'])->toBe('text/plain; charset=utf-8');
});

test('repeated headers come back as a list', function () {
	expect($this->client->send(new ClientRequest('GET', serverUrl('/cookies')))->headers['set-cookie'])->toBe([
		'a=1; Path=/',
		'b=2; Path=/',
	]);
});

test('a 404 is a response, but a failed connection throws', function () {
	expect($this->client->send(new ClientRequest('GET', serverUrl('/nowhere')))->status)->toBe(404);
	expect(fn() => new HttpClient(timeout: 2)->send(new ClientRequest('GET', 'http://127.0.0.1:1/')))
		->toThrow(RuntimeException::class, 'GET http://127.0.0.1:1/ failed:');
});

test('a PUT without a body still says its length is 0, since some servers refuse it otherwise', function () {
	$echo = $this->client->send(new ClientRequest('PUT', serverUrl('/echo/7')))->data();

	expect($echo->get('content_length')->string())->toBe('0');
});

test('a body with no Content-Type of its own goes without one, rather than labelled as a form', function () {
	$empty = $this->client->send(ClientRequest::json('PUT', serverUrl('/echo/7')))->data();
	$raw = $this->client->send(new ClientRequest('PUT', serverUrl('/echo/7'), 'plain'))->data();

	expect($empty->get('content_type')->raw)->toBeNull();
	expect($raw->get('content_type')->raw)->toBeNull();
	expect($raw->get('body')->string())->toBe('plain');
});

test('only http and https are allowed, so a file:// URL is refused', function () {
	expect(fn() => $this->client->send(new ClientRequest('GET', 'file:///etc/hosts')))
		->toThrow(RuntimeException::class, 'GET file:///etc/hosts failed:');
});

test('sendAll() returns the responses in the order of the requests', function () {
	[$listed, $echo] = $this->client->sendAll([
		new ClientRequest('GET', serverUrl('/notes')),
		ClientRequest::json('PUT', serverUrl('/echo/7'), ['name' => 'Ann']),
	]);

	expect($listed->body)->toBe('notes');
	expect($echo->data()->get('name')->string())->toBe('Ann');
});

test('sendAll() throws for a failed connection, naming the request', function () {
	$requests = [new ClientRequest('GET', serverUrl('/notes')), new ClientRequest('GET', 'http://127.0.0.1:1/')];

	expect(fn() => new HttpClient(timeout: 2)->sendAll($requests))
		->toThrow(RuntimeException::class, 'GET http://127.0.0.1:1/ failed:');
});
