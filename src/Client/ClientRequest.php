<?php

namespace Sapling\Client;

use Sapling\Data;

/**
 * A request for HttpClient to send, as in
 * ClientRequest::json('POST', 'https://api.example.com/users', ['name' => 'Ann']).
 */
final readonly class ClientRequest
{
	/** @var array<string, string> Lowercase names, as HTTP treats them. */
	public array $headers;

	/**
	 * A string body goes as it is, so pass its Content-Type. An array goes as multipart form data, which can hold
	 * CURLFile uploads. A header named twice in different cases keeps the last one.
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
	}

	/**
	 * A request with $data as JSON, where [] sends [] and new stdClass() sends {}. Without data, there's no body.
	 *
	 * @param array<string, string> $headers
	 * @throws \JsonException When $data can't be encoded.
	 */
	public static function json(string $method, string $url, mixed $data = null, array $headers = []): self
	{
		if ($data === null) {
			return new self($method, $url, '', $headers);
		}

		$body = Data::toJson($data);

		return new self($method, $url, $body, ['content-type' => 'application/json', ...$headers]);
	}

	/**
	 * A request with $data as a URL-encoded form.
	 *
	 * @param array<array-key, mixed> $data
	 * @param array<string, string> $headers
	 */
	public static function form(string $method, string $url, array $data, array $headers = []): self
	{
		$body = Data::toForm($data);

		return new self($method, $url, $body, ['content-type' => 'application/x-www-form-urlencoded', ...$headers]);
	}

	/**
	 * A request with $data as XML inside a $root element.
	 *
	 * @param array<array-key, mixed> $data
	 * @param array<string, string> $headers
	 */
	public static function xml(
		string $method,
		string $url,
		array $data,
		string $root = 'root',
		array $headers = [],
	): self {
		$body = Data::toXml($data, $root);

		return new self($method, $url, $body, ['content-type' => 'application/xml', ...$headers]);
	}

	/**
	 * A request with a plain text body.
	 *
	 * @param array<string, string> $headers
	 */
	public static function text(string $method, string $url, string $text, array $headers = []): self
	{
		return new self($method, $url, $text, ['content-type' => 'text/plain; charset=utf-8', ...$headers]);
	}
}
