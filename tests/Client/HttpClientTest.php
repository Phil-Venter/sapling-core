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

test('a 404 is a reply, and a failed connection is a reply with status 0 and the error as its body', function () {
	$missing = $this->client->send(new ClientRequest('GET', serverUrl('/nowhere')));
	$down = new HttpClient(timeout: 2)->send(new ClientRequest('GET', 'http://127.0.0.1:1/'));

	expect($missing->status)->toBe(404);
	expect($down->status)->toBe(0);
	expect($down->body)->toStartWith('GET http://127.0.0.1:1/ failed:');
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
	$reply = $this->client->send(new ClientRequest('GET', 'file:///etc/hosts'));

	expect($reply->status)->toBe(0);
	expect($reply->body)->toStartWith('GET file:///etc/hosts failed:');
});

test('sendAll() returns the replies in order, under the keys of the requests', function () {
	$replies = $this->client->sendAll([
		'listed' => new ClientRequest('GET', serverUrl('/notes')),
		'echo' => ClientRequest::json('PUT', serverUrl('/echo/7'), ['name' => 'Ann']),
	]);

	expect(array_keys($replies))->toBe(['listed', 'echo']);
	expect($replies['listed']->body)->toBe('notes');
	expect($replies['echo']->data()->get('name')->string())->toBe('Ann');
});

test('sendAll() queues requests past its six connections to one host, and answers every one', function () {
	$requests = array_fill(0, 20, new ClientRequest('GET', serverUrl('/notes')));

	$replies = $this->client->sendAll($requests);

	expect($replies)->toHaveCount(20);
	expect(array_unique(array_map(fn($reply) => $reply->body, $replies)))->toBe(['notes']);
});

test('sendAll() returns a failed connection as status 0 with the error, and keeps the other replies', function () {
	$replies = new HttpClient(timeout: 2)->sendAll([
		'listed' => new ClientRequest('GET', serverUrl('/notes')),
		'down' => new ClientRequest('GET', 'http://127.0.0.1:1/'),
	]);

	expect($replies['listed']->body)->toBe('notes');
	expect($replies['down']->status)->toBe(0);
	expect($replies['down']->body)->toStartWith('GET http://127.0.0.1:1/ failed:');
});
