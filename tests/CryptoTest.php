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

test('a tampered value or the wrong key decrypts to null', function () {
	$stored = $this->crypto->encrypt('evernote-key', 'tenant:7:evernote');
	$other = new Crypto(base64_encode(random_bytes(32)));
	$changed = substr($stored, 0, -4) . 'AAA=';

	expect($other->decrypt($stored, 'tenant:7:evernote'))->toBeNull();
	expect($this->crypto->decrypt($changed, 'tenant:7:evernote'))->toBeNull();
	expect($this->crypto->decrypt('not base64!', 'tenant:7:evernote'))->toBeNull();
	expect($this->crypto->decrypt(substr($stored, 0, 8), 'tenant:7:evernote'))->toBeNull();
});

test('a key that is not 32 base64-encoded bytes is refused', function (string $key) {
	expect(fn() => new Crypto($key))
		->toThrow(InvalidArgumentException::class, 'app_key must be 32 bytes, base64-encoded');
})->with(['too short' => base64_encode('short'), 'not base64' => '%%%', 'empty' => '']);
