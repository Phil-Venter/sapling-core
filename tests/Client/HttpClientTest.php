<?php

use Sapling\Client\ClientRequest;
use Sapling\Client\ClientResponse;
use Sapling\Client\HttpClient;
use Sapling\Data;

function serverUrl(string $path): string
{
	return 'http://' . httpServer() . $path;
}

beforeEach(fn() => $this->client = new HttpClient());

test('a fake answers every request instead of curl', function () {
	$sent = [];
	$client = new HttpClient()->fake(function (ClientRequest $request) use (&$sent): ClientResponse {
		$sent[] = "{$request->method} {$request->url}";

		return new ClientResponse('{"paid":true}', 200, ['Content-Type' => 'application/json']);
	});

	$reply = $client->send(ClientRequest::json('POST', 'https://pay.example/charge', ['amount' => 500]));
	$replies = $client->sendAll(['a' => ClientRequest::json('GET', 'https://pay.example/a')]);

	expect($reply->data('paid')->bool())->toBeTrue();
	expect(array_keys($replies))->toBe(['a']);
	expect($sent)->toBe(['POST https://pay.example/charge', 'GET https://pay.example/a']);
});

test('send() passes a string body and its Content-Type as they are', function () {
	$headers = ['X-Test' => 'header value', 'Content-Type' => 'application/json'];
	$request = new ClientRequest('PUT', serverUrl('/echo/7?q=search'), Data::toJson(['name' => 'Ann']), $headers);
	$echo = $this->client->send($request)->data()->rawArray();

	expect($echo)->toMatchArray([
		'method' => 'PUT',
		'id' => '7',
		'name' => 'Ann',
		'q' => 'search',
		'header' => 'header value',
		'content_type' => 'application/json',
	]);
});

test('only a GET follows redirects', function () {
	$followed = $this->client->send(new ClientRequest('GET', serverUrl('/old-address')));
	$returned = $this->client->send(ClientRequest::form('POST', serverUrl('/notes'), ['body' => 'x']));

	expect([$followed->status, $followed->body])->toBe([200, 'notes']);
	expect($followed->headers)->not->toHaveKey('location');
	expect([$returned->status, $returned->headers['location']])->toBe([302, '/notes']);
});

test('HEAD returns the headers with an empty body', function () {
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

test('an error status or a failed connection is still a reply', function () {
	$missing = $this->client->send(new ClientRequest('GET', serverUrl('/nowhere')));
	$down = new HttpClient(timeout: 2)->send(new ClientRequest('GET', 'http://127.0.0.1:1/'));

	expect($missing->status)->toBe(404);
	expect($down->status)->toBe(0);
	expect($down->body)->toStartWith('GET http://127.0.0.1:1/ failed:');
});

test('a PUT without a body sends Content-Length: 0', function () {
	$echo = $this->client->send(new ClientRequest('PUT', serverUrl('/echo/7')))->data();

	expect($echo->get('content_length')->string())->toBe('0');
});

test('a body without a Content-Type is sent without one', function () {
	$empty = $this->client->send(ClientRequest::json('PUT', serverUrl('/echo/7')))->data();
	$raw = $this->client->send(new ClientRequest('PUT', serverUrl('/echo/7'), 'plain'))->data();

	expect($empty->get('content_type')->raw)->toBeNull();
	expect($raw->get('content_type')->raw)->toBeNull();
	expect($raw->get('body')->string())->toBe('plain');
});

test('only http and https URLs are allowed', function () {
	$reply = $this->client->send(new ClientRequest('GET', 'file:///etc/hosts'));

	expect($reply->status)->toBe(0);
	expect($reply->body)->toStartWith('GET file:///etc/hosts failed:');
});

test('a redirect to anything but http or https is refused', function () {
	$url = serverUrl('/to-a-file');

	$reply = $this->client->send(new ClientRequest('GET', $url));

	expect($reply->status)->toBe(0);
	expect($reply->body)->toStartWith("GET {$url} failed:");
});

test('sendAll() keeps the requests\' keys and order', function () {
	$replies = $this->client->sendAll([
		'listed' => new ClientRequest('GET', serverUrl('/notes')),
		'echo' => ClientRequest::json('PUT', serverUrl('/echo/7'), ['name' => 'Ann']),
	]);

	expect(array_keys($replies))->toBe(['listed', 'echo']);
	expect($replies['listed']->body)->toBe('notes');
	expect($replies['echo']->data()->get('name')->string())->toBe('Ann');
});

test('sendAll() answers twenty requests to one host', function () {
	$requests = array_fill(0, 20, new ClientRequest('GET', serverUrl('/notes')));

	$replies = $this->client->sendAll($requests);

	expect($replies)->toHaveCount(20);
	expect(array_unique(array_map(fn($reply) => $reply->body, $replies)))->toBe(['notes']);
});

test('sendAll() keeps the other replies when one connection fails', function () {
	$replies = new HttpClient(timeout: 2)->sendAll([
		'listed' => new ClientRequest('GET', serverUrl('/notes')),
		'down' => new ClientRequest('GET', 'http://127.0.0.1:1/'),
	]);

	expect($replies['listed']->body)->toBe('notes');
	expect($replies['down']->status)->toBe(0);
	expect($replies['down']->body)->toStartWith('GET http://127.0.0.1:1/ failed:');
});
