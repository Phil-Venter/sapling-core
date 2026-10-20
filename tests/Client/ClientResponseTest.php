<?php

use Sapling\Client\ClientResponse;

test('header() ignores the case of the name', function () {
	$reply = new ClientResponse('', 200, ['Retry-After' => '60']);

	expect($reply->headers)->toBe(['retry-after' => '60']);
	expect($reply->header('Retry-After')->int())->toBe(60);
});

test('data() goes by the first of two Content-Types', function () {
	$reply = new ClientResponse('{"saved":true}', 200, ['content-type' => ['application/json', 'text/plain']]);

	expect($reply->data('saved')->bool())->toBeTrue();
	expect($reply->data()->rawArray())->toBe(['saved' => true]);
});
