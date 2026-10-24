<?php

use Sapling\Crypto;

beforeEach(function () {
	$this->crypto = new Crypto(base64_encode(random_bytes(32)));
});

test('a secret decrypts only with its own context', function () {
	$stored = $this->crypto->encrypt('evernote-key', 'tenant:7:evernote');

	expect($this->crypto->decrypt($stored, 'tenant:7:evernote'))->toBe('evernote-key');
	expect($this->crypto->decrypt($stored, 'tenant:8:evernote'))->toBeNull();
});

test('the same secret encrypts differently every time', function () {
	$first = $this->crypto->encrypt('evernote-key', 'tenant:7:evernote');
	$second = $this->crypto->encrypt('evernote-key', 'tenant:7:evernote');

	expect($first)->not->toBe($second);
});

test('a tampered value or the wrong key decrypts to null', function (Closure $decrypt) {
	$stored = $this->crypto->encrypt('evernote-key', 'tenant:7:evernote');

	expect($decrypt($this->crypto, $stored))->toBeNull();
})->with([
	'the wrong key' => [
		fn(Crypto $crypto, string $stored) => new Crypto(base64_encode(random_bytes(32)))->decrypt(
			$stored,
			'tenant:7:evernote',
		),
	],
	'a changed value' => [
		fn(Crypto $crypto, string $stored) => $crypto->decrypt(substr($stored, 0, -4) . 'AAA=', 'tenant:7:evernote'),
	],
	'not base64' => [fn(Crypto $crypto, string $stored) => $crypto->decrypt('not base64!', 'tenant:7:evernote')],
	'too short for a nonce' => [
		fn(Crypto $crypto, string $stored) => $crypto->decrypt(substr($stored, 0, 8), 'tenant:7:evernote'),
	],
]);

test('a key that is not 32 base64-encoded bytes is refused', function (string $key) {
	expect(fn() => new Crypto($key))
		->toThrow(InvalidArgumentException::class, 'app_key must be 32 bytes, base64-encoded');
})->with(['too short' => base64_encode('short'), 'not base64' => '%%%', 'empty' => '']);
