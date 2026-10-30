<?php

namespace Sapling\Http;

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
	 * A POST with _method set to PUT, PATCH or DELETE becomes that method. When the connection comes from one of $trustedProxies, or any with ['*'], the ip is the visitor's, from X-Forwarded-For.
	 *
	 * @param list<string> $trustedProxies
	 */
	public static function fromGlobals(array $trustedProxies = []): self
	{
		return new self(
			method: self::readMethod(),
			path: self::readPath(),
			query: $_GET,
			input: $_POST,
			headers: self::readHeaders(),
			cookies: $_COOKIE,
			files: $_FILES,
			body: (string) file_get_contents('php://input'),
			ip: self::readIp($trustedProxies),
		);
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

		// PHP leaves these two out of the HTTP_ keys.
		foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
			$value = $_SERVER[$key] ?? null;
			if (is_string($value)) {
				$headers[$name] = $value;
			}
		}

		return $headers;
	}

	/** @param list<string> $trusted */
	private static function readIp(array $trusted): string
	{
		$remote = $_SERVER['REMOTE_ADDR'] ?? '';
		$forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
		$any = in_array('*', $trusted, true);
		$fromProxy = $any || in_array($remote, $trusted, true);
		if (!is_string($forwarded) || !$fromProxy) {
			return $remote;
		}

		// Each proxy adds the address it saw, so the visitor is the last one no trusted proxy added. No address matches '*', which leaves the one the connecting proxy added.
		$hops = array_map(trim(...), explode(',', $forwarded));
		foreach (array_reverse($hops) as $hop) {
			if (!in_array($hop, $trusted, true)) {
				return $hop;
			}
		}

		return $hops[0];
	}

	/**
	 * A :name parameter from the route path. Without a name, every parameter.
	 */
	public function param(string|int ...$keys): Value
	{
		return new Value($this->params)->get(...$keys);
	}

	/**
	 * More keys read deeper, as in query('filter', 'status') for ?filter[status]=open.
	 */
	public function query(string|int ...$keys): Value
	{
		return new Value($this->query)->get(...$keys);
	}

	/**
	 * More keys read deeper, as in cookie('prefs', 'theme') for prefs[theme]=dark.
	 */
	public function cookie(string|int ...$keys): Value
	{
		return new Value($this->cookies)->get(...$keys);
	}

	/**
	 * The name is case-insensitive.
	 */
	public function header(string $name): Value
	{
		return new Value($this->headers[strtolower($name)] ?? null);
	}

	/**
	 * The body, decoded as JSON or a form by its Content-Type. Any other body stays a string. More keys read deeper, as Value::get() does.
	 *
	 * @throws HttpException 400 when the body doesn't decode.
	 */
	public function data(string|int ...$keys): Value
	{
		$this->data ??= $this->decode();
		return new Value($this->data)->get(...$keys);
	}

	/** @throws HttpException */
	private function decode(): mixed
	{
		// PHP keeps no copy of a multipart body, only $_POST.
		if ($this->input !== []) {
			return $this->input;
		}

		try {
			$type = $this->headers['content-type'] ?? '';
			return Data::decode($this->body, $type);
		} catch (\JsonException $e) {
			throw new HttpException(400, 'The request body is invalid', previous: $e);
		}
	}
}
