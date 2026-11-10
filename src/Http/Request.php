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

	public readonly string $path;

	/**
	 * A request built by hand, for tests. Use fromGlobals() for the current request. The path loses its repeated and trailing slashes.
	 *
	 * @param array<array-key, mixed> $query
	 * @param array<array-key, mixed> $input
	 * @param array<string, string> $headers
	 * @param array<array-key, mixed> $cookies
	 * @param array<array-key, mixed> $files
	 */
	public function __construct(
		public readonly string $method,
		string                 $path,
		private array          $query = [],
		private array          $input = [],
		private array          $headers = [],
		private array          $cookies = [],
		public readonly array  $files = [],
		public readonly string $body = '',
		public readonly string $ip = '',
	) {
		$path = (string) preg_replace('#/+#', '/', $path);
		$this->path = '/' . trim($path, '/');
		$this->headers = array_change_key_case($headers, CASE_LOWER);
	}

	/**
	 * A POST with _method set to PUT, PATCH or DELETE becomes that method. A multipart body sent as one of those is read as a POST's is. When the connection comes from one of $trustedProxies, or any with ['*'], the ip is the visitor's, from X-Forwarded-For.
	 *
	 * @param list<string> $trustedProxies
	 */
	public static function fromGlobals(array $trustedProxies = []): self
	{
		[$input, $files] = self::readForm();
		$uri = $_SERVER['REQUEST_URI'] ?? '/';

		return new self(
			method: self::readMethod(),
			path: explode('?', $uri, 2)[0],
			query: $_GET,
			input: $input,
			headers: self::readHeaders(),
			cookies: $_COOKIE,
			files: $files,
			body: (string) file_get_contents('php://input'),
			ip: self::readIp($trustedProxies),
		);
	}

	/** @return array{array<array-key, mixed>, array<array-key, mixed>} */
	private static function readForm(): array
	{
		$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		$type = $_SERVER['CONTENT_TYPE'] ?? '';

		if ($method === 'POST' || !str_starts_with(strtolower($type), 'multipart/form-data')) {
			return [$_POST, $_FILES];
		}

		// PHP reads a multipart body into $_POST and $_FILES for a POST only. A body it can't read leaves both empty, as it does for a POST.
		try {
			return request_parse_body();
		} catch (\RequestParseBodyException) {
			return [[], []];
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
		$fromProxy = in_array('*', $trusted, true) || in_array($remote, $trusted, true);

		if (!is_string($forwarded) || !$fromProxy) {
			return $remote;
		}

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
	 * The body, decoded as JSON or a form by its Content-Type. Any other body stays a string.
	 *
	 * @throws HttpException 400 when the body doesn't decode.
	 */
	public function data(string|int ...$keys): Value
	{
		if ($this->input !== []) {
			return new Value($this->input)->get(...$keys);
		}

		$contentType = $this->headers['content-type'] ?? '';

		// parse_str() warns about a form with more fields than max_input_vars, and handle() throws the warning as an ErrorException.
		try {
			return new Value(Data::decode($this->body, $contentType))->get(...$keys);
		} catch (\JsonException|\ErrorException $e) {
			throw new HttpException(400, 'The request body is invalid', previous: $e);
		}
	}
}
