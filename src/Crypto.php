<?php

namespace Sapling;

/**
 * Encrypts secrets for storage with app_key, using XChaCha20-Poly1305.
 */
final readonly class Crypto
{
	private const int KEY_BYTES = 32;

	private const int NONCE_BYTES = 24;

	private string $key;

	/**
	 * Takes app_key, which is 32 random bytes, base64-encoded.
	 */
	public function __construct(#[\SensitiveParameter] string $key)
	{
		$decoded = base64_decode($key, true);

		if ($decoded === false || strlen($decoded) !== self::KEY_BYTES) {
			throw new \InvalidArgumentException('app_key must be 32 bytes, base64-encoded');
		}

		$this->key = $decoded;
	}

	/**
	 * Encrypts a secret for storage. Decrypting needs the same context, such as 'tenant:7:api_key'.
	 */
	public function encrypt(#[\SensitiveParameter] string $secret, string $context): string
	{
		$nonce = random_bytes(self::NONCE_BYTES);
		$cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($secret, $context, $nonce, $this->key);

		return base64_encode($nonce . $cipher);
	}

	/**
	 * The secret, or null when the stored value was changed, the context differs or the key is wrong.
	 */
	public function decrypt(string $stored, string $context): ?string
	{
		$raw = base64_decode($stored, true);

		// Too short to hold a nonce, such as a value cut off by its column. Sodium would throw.
		if ($raw === false || strlen($raw) < self::NONCE_BYTES) {
			return null;
		}

		$nonce = substr($raw, 0, self::NONCE_BYTES);
		$cipher = substr($raw, self::NONCE_BYTES);
		$secret = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, $context, $nonce, $this->key);

		if ($secret === false) {
			return null;
		}

		return $secret;
	}
}
