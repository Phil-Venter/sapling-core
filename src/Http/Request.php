<?php

namespace Sapling\Http;

use JsonException;
use RuntimeException;
use Sapling\Data;
use Sapling\Value;

/**
 * An HTTP request, with its route parameters, query, body fields, headers and cookies.
 */
final class Request
{
	/** @var array<string, string> */
	public array $params = [];

	private mixed $data = null;

	private bool $decoded = false;

	/**
	 * A request built by hand, for tests. Use fromGlobals() for the current request.
	 *
	 * @param array<array-key, mixed> $query
	 * @param array<array-key, mixed> $input
	 * @param array<string, string> $headers
	 * @param array<array-key, mixed> $cookies
	 * @param array<array-key, mixed> $files
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $path,
		private array          $query = [],
		private array          $input = [],
		private array          $headers = [],
		private array          $cookies = [],
		public readonly array  $files = [],
		public readonly string $body = '',
		public readonly string $ip = '',
	) {
		$this->headers = array_change_key_case($headers, CASE_LOWER);
	}

	/**
	 * The current request, from PHP's globals. A POST with _method set to PUT, PATCH or DELETE becomes that
	 * method.
	 */
	public static function fromGlobals(): self
	{
		$headers = self::readHeaders();
		$body = (string) file_get_contents('php://input');

		return new self(
			method: self::readMethod(),
			path: self::readPath(),
			query: $_GET,
			input: $_POST,
			headers: $headers,
			cookies: $_COOKIE,
			files: $_FILES,
			body: $body,
			ip: $_SERVER['REMOTE_ADDR'] ?? '',
		);
	}

	/**
	 * A :name parameter from the route path, as in param('id')->int().
	 */
	public function param(string $name): Value
	{
		return new Value($this->params[$name] ?? null);
	}

	/**
	 * A query string value, read as the type you need, as in query('page')->int(1).
	 */
	public function query(string $name): Value
	{
		return new Value($this->query[$name] ?? null);
	}

	/**
	 * A field from the body, read as the type you need, as in input('body')->string(). Short for data()->get($name).
	 *
	 * @throws HttpException 400 when the body doesn't decode.
	 */
	public function input(string $name): Value
	{
		return $this->data()->get($name);
	}

	/**
	 * The body decoded by its Content-Type, as JSON, XML or a form, as Response::data() does. It's decoded the first
	 * time it's read.
	 *
	 * @throws HttpException 400 when the body doesn't decode.
	 */
	public function data(): Value
	{
		if (!$this->decoded) {
			$this->data = $this->decode();
			$this->decoded = true;
		}

		return new Value($this->data);
	}

	/**
	 * A request header, whatever the case of its name, as in header('accept')->string().
	 */
	public function header(string $name): Value
	{
		return new Value($this->headers[strtolower($name)] ?? null);
	}

	/**
	 * A cookie, read as the type you need, as in cookie('theme')->string('light').
	 */
	public function cookie(string $name): Value
	{
		return new Value($this->cookies[$name] ?? null);
	}

	/** @throws HttpException */
	private function decode(): mixed
	{
		// PHP keeps no copy of a multipart body, only $_POST.
		if ($this->input !== []) {
			return $this->input;
		}

		$type = $this->headers['content-type'] ?? '';

		try {
			return Data::decode($this->body, $type);
		} catch (JsonException|RuntimeException $e) {
			throw new HttpException(400, 'The request body is invalid', previous: $e);
		}
	}

	private static function readMethod(): string
	{
		$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		$override = $_POST['_method'] ?? null;

		if ($method !== 'POST' || !is_string($override)) {
			return $method;
		}

		$override = strtoupper($override);

		if (!in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
			return $method;
		}

		return $override;
	}

	private static function readPath(): string
	{
		$uri = $_SERVER['REQUEST_URI'] ?? '/';
		$path = explode('?', $uri, 2)[0];
		$path = (string) preg_replace('#/+#', '/', $path);

		return '/' . trim($path, '/');
	}

	/** @return array<string, string> */
	private static function readHeaders(): array
	{
		$headers = [];

		foreach ($_SERVER as $key => $value) {
			if (!str_starts_with($key, 'HTTP_') || !is_string($value)) {
				continue;
			}

			$name = str_replace('_', '-', strtolower(substr($key, 5)));
			$headers[$name] = $value;
		}

		$type = $_SERVER['CONTENT_TYPE'] ?? null;

		if (is_string($type)) {
			$headers['content-type'] = $type;
		}

		return $headers;
	}
}
