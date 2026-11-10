<?php

namespace Sapling\Client;

use Sapling\Data;

/**
 * A request for HttpClient to send.
 */
final readonly class ClientRequest
{
	/** @var array<string, string> Lowercase names. */
	public array $headers;

	/**
	 * A string body goes as it is, so pass its Content-Type. An array goes as multipart form data, which can hold CURLFile uploads. A header named twice in different cases keeps the last one, and one with a line break or NUL in it throws an InvalidArgumentException.
	 *
	 * @param string|array<string, mixed> $body
	 * @param array<string, string> $headers
	 */
	public function __construct(
		public string $method,
		public string $url,
		public string|array $body = '',
		array $headers = [],
	) {
		$this->headers = array_change_key_case($headers);

		// curl sends a header as it's given, so a line break in one would start another.
		foreach ($this->headers as $name => $value) {
			if (preg_match('/[\r\n\0]/', "{$name}{$value}") === 1) {
				throw new \InvalidArgumentException("Header {$name} has a line break or NUL in it");
			}
		}
	}

	/**
	 * [] sends [] and new stdClass() sends {}. Without $data, there's no body.
	 *
	 * @param array<string, string> $headers
	 * @throws \JsonException When $data can't be encoded.
	 */
	public static function json(string $method, string $url, mixed $data = null, array $headers = []): self
	{
		if ($data === null) {
			return new self($method, $url, '', $headers);
		}

		$type = 'application/json';
		return new self($method, $url, Data::toJson($data), ['content-type' => $type, ...$headers]);
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @param array<string, string> $headers
	 */
	public static function form(string $method, string $url, array $data, array $headers = []): self
	{
		$type = 'application/x-www-form-urlencoded';
		return new self($method, $url, Data::toForm($data), ['content-type' => $type, ...$headers]);
	}

	/** @param array<string, string> $headers */
	public static function text(string $method, string $url, string $text, array $headers = []): self
	{
		$type = 'text/plain; charset=utf-8';
		return new self($method, $url, $text, ['content-type' => $type, ...$headers]);
	}
}
