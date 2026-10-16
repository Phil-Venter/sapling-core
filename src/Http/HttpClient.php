<?php

namespace Sapling\Http;

use JsonException;
use RuntimeException;
use Sapling\Data;

/**
 * Calls other HTTP services through curl.
 */
final readonly class HttpClient
{
	/**
	 * Requests give up after $timeout seconds.
	 */
	public function __construct(private int $timeout = 30) {}

	/**
	 * Sends $data as JSON, as in json('POST', 'https://api.example.com/users', ['name' => 'Ann']). With no data,
	 * there's no body.
	 *
	 * @param array<array-key, mixed> $data
	 * @param array<string, string> $headers
	 * @throws JsonException When $data can't be encoded.
	 * @throws RuntimeException When the connection fails or times out. An error status is a normal response.
	 */
	public function json(string $method, string $url, array $data = [], array $headers = []): Response
	{
		$headers = ['content-type' => 'application/json', ...$headers];

		if ($data === []) {
			return $this->request($method, $url, headers: $headers);
		}

		return $this->request($method, $url, Data::toJson($data), $headers);
	}

	/**
	 * Sends a request and returns the response, following redirects for GET and HEAD. A string body goes as it is,
	 * so pass its Content-Type, and an array goes as multipart form data.
	 *
	 * @param string|array<string, mixed> $body
	 * @param array<string, string> $headers
	 * @throws RuntimeException When the connection fails or times out. An error status is a normal response.
	 */
	public function request(string $method, string $url, string|array $body = '', array $headers = []): Response
	{
		$lines = [];

		// So Content-Type and content-type are one header, and the last one wins.
		foreach (array_change_key_case($headers) as $name => $value) {
			$lines[] = "{$name}: {$value}";
		}

		/** @var array<string, list<string>> $received */
		$received = [];
		$curl = curl_init($url);

		curl_setopt_array($curl, [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => $lines,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => $this->timeout,
			CURLOPT_ENCODING => '',
			CURLOPT_NOBODY => $method === 'HEAD',
			CURLOPT_FOLLOWLOCATION => in_array($method, ['GET', 'HEAD'], true),
			CURLOPT_MAXREDIRS => 5,
			CURLOPT_HEADERFUNCTION => function ($_, string $line) use (&$received): int {
				// A redirect starts a new set of headers.
				if (str_starts_with($line, 'HTTP/')) {
					$received = [];
				}

				if (str_contains($line, ':')) {
					$parts = explode(':', $line, 2);
					$name = strtolower(trim($parts[0]));
					$received[$name][] = trim($parts[1] ?? '');
				}

				return strlen($line);
			},
		]);

		if ($body !== '' && $body !== []) {
			curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
		}

		$content = curl_exec($curl);

		if (!is_string($content)) {
			throw new RuntimeException("{$method} {$url} failed: " . curl_error($curl));
		}

		$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

		return new Response($content, $status, self::collapseHeaders($received));
	}

	/**
	 * @param array<string, list<string>> $received
	 * @return array<string, string|list<string>>
	 */
	private static function collapseHeaders(array $received): array
	{
		$headers = [];

		foreach ($received as $name => $values) {
			if (count($values) === 1) {
				$values = $values[0];
			}

			$headers[$name] = $values;
		}

		return $headers;
	}
}
