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
	 * method. When the connection comes from one of $trustedProxies, or any with ['*'], the ip is the visitor's,
	 * from X-Forwarded-For.
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

		// Each proxy adds the address it saw, so the visitor is the last one no trusted proxy added. With '*', only the
		// proxy that connected is trusted, because the visitor can write anything before the address it added.
		$hops = array_map(trim(...), explode(',', $forwarded));

		if ($any) {
			return end($hops);
		}

		foreach (array_reverse($hops) as $hop) {
			if (!in_array($hop, $trusted, true)) {
				return $hop;
			}
		}

		return $hops[0];
	}

	/**
	 * A :name parameter from the route path, as in param('id')->int(). Without a name, every parameter.
	 */
	public function param(string|int ...$keys): Value
	{
		return new Value($this->params)->get(...$keys);
	}

	/**
	 * A query string value, read as the type you need, as in query('page')->int(1). More keys read deeper, as in
	 * query('filter', 'status') for ?filter[status]=open, and no key gives the whole query string.
	 */
	public function query(string|int ...$keys): Value
	{
		return new Value($this->query)->get(...$keys);
	}

	/**
	 * A field from the body, decoded by its Content-Type as JSON, XML or a form, as in data('title')->string(). More
	 * keys read deeper, and no key gives the whole body. It's decoded the first time it's read.
	 *
	 * @throws HttpException 400 when the body doesn't decode.
	 */
	public function data(string|int ...$keys): Value
	{
		if (!$this->decoded) {
			$this->data = $this->decode();
			$this->decoded = true;
		}

		return new Value($this->data)->get(...$keys);
	}

	/**
	 * A request header, ignoring the case of its name, as in header('accept')->string().
	 */
	public function header(string $name): Value
	{
		return new Value($this->headers[strtolower($name)] ?? null);
	}

	/**
	 * A cookie, read as the type you need, as in cookie('theme')->string('light'). More keys read deeper, as in
	 * cookie('prefs', 'theme') for prefs[theme]=dark.
	 */
	public function cookie(string|int ...$keys): Value
	{
		return new Value($this->cookies)->get(...$keys);
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
		} catch (\JsonException|\RuntimeException $e) {
			throw new HttpException(400, 'The request body is invalid', previous: $e);
		}
	}
}
