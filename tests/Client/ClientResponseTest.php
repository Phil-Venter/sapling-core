<?php

use Sapling\Client\ClientResponse;

test('header names are lowercased, and header() finds one whatever the case you ask in', function () {
	$reply = new ClientResponse('', 200, ['Retry-After' => '60']);

	expect($reply->headers)->toBe(['retry-after' => '60']);
	expect($reply->header('Retry-After')->int())->toBe(60);
});

test('data() reads a field, and goes by the first Content-Type when the service sent two', function () {
	$reply = new ClientResponse('{"saved":true}', 200, ['content-type' => ['application/json', 'text/plain']]);

	expect($reply->data('saved')->bool())->toBeTrue();
	expect($reply->data()->rawArray())->toBe(['saved' => true]);
});
