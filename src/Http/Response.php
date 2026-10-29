<?php

namespace Sapling\Http;

use Sapling\Data;
use Sapling\Value;

/**
 * An HTTP response, with its status, headers and a body or a file.
 */
final class Response
{
	public private(set) ?string $file = null;

	/**
	 * Use html(), text(), json(), redirect() or file() for the common cases.
	 *
	 * @param array<string, string|list<string>> $headers
	 */
	public function __construct(
		public private(set) string $body = '',
		public private(set) int    $status = 200,
		public private(set) array  $headers = [],
	) {}

	public static function html(string $html, int $status = 200): self
	{
		return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
	}

	public static function text(string $text, int $status = 200): self
	{
		return new self($text, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
	}

	/** @throws \JsonException When the data can't be encoded. */
	public static function json(mixed $data, int $status = 200): self
	{
		return new self(Data::toJson($data), $status, ['Content-Type' => 'application/json']);
	}

	public static function redirect(string $url, int $status = 302): self
	{
		return new self('', $status, ['Location' => $url]);
	}

	/**
	 * A download, named after the file unless $name is given.
	 *
	 * @throws \RuntimeException When the file is missing.
	 */
	public static function file(string $path, ?string $name = null): self
	{
		if (!is_file($path)) {
			throw new \RuntimeException("File not found: {$path}");
		}

		$name ??= basename($path);
		$ascii = (string) preg_replace('/[^A-Za-z0-9._ -]/u', '_', $name);
		$utf8 = rawurlencode($name);

		$response = new self('', 200, [
			'Content-Type' => 'application/octet-stream',
			'Content-Length' => (string) filesize($path),
			'Content-Disposition' => "attachment; filename=\"{$ascii}\"; filename*=UTF-8''{$utf8}",
		]);

		$response->file = $path;
		return $response;
	}

	/**
	 * The error's status and message as plain text. App adds its headers.
	 */
	public static function error(HttpException $e): self
	{
		return self::text($e->getMessage(), $e->status);
	}

	/**
	 * A 500 for a crash. In debug mode it's the debug page, otherwise a plain Internal Server Error.
	 */
	public static function fromCrash(\Throwable $e, bool $debug, string $basePath): self
	{
		if (!$debug) {
			return self::text('Internal Server Error', 500);
		}

		return self::html(DebugPage::render($e, $basePath), 500);
	}

	/**
	 * The name is case-insensitive. One set to a list, such as several Set-Cookie values, is read with rawArray().
	 */
	public function header(string $name): Value
	{
		foreach ($this->headers as $key => $value) {
			if (strcasecmp($key, $name) === 0) {
				return new Value($value);
			}
		}

		return new Value(null);
	}

	/**
	 * A copy with the header set, replacing any of the same name, ignoring case.
	 *
	 * @param string|list<string> $value
	 */
	#[\NoDiscard('the original response is unchanged')]
	public function withHeader(string $name, string|array $value): self
	{
		$copy = clone $this;
		foreach (array_keys($copy->headers) as $existing) {
			if (strcasecmp($existing, $name) !== 0) {
				continue;
			}

			unset($copy->headers[$existing]);
		}

		$copy->headers[$name] = $value;
		return $copy;
	}

	/**
	 * The body, decoded as JSON, XML or a form by its Content-Type. Any other body stays a string. More keys read deeper, as Value::get() does.
	 *
	 * @throws \JsonException When a JSON body is invalid.
	 * @throws \RuntimeException When an XML body is invalid.
	 */
	public function data(string|int ...$keys): Value
	{
		$contentType = $this->header('content-type')->string();
		return new Value(Data::decode($this->body, $contentType))->get(...$keys);
	}

	/**
	 * With body: false, only the status and headers.
	 */
	public function send(bool $body = true): void
	{
		foreach ($this->headers as $name => $values) {
			$replace = strcasecmp($name, 'Set-Cookie') !== 0;
			foreach ((array) $values as $value) {
				header("{$name}: {$value}", $replace);
				$replace = false;
			}
		}

		// After the headers, since header('Location: ...') turns any status but 201 and 3xx into a 302.
		http_response_code($this->status);

		$bodiless = $this->status < 200 || $this->status === 204 || $this->status === 304;
		if (!$body || $bodiless) {
			return;
		}

		if ($this->file !== null) {
			readfile($this->file);
			return;
		}

		echo $this->body;
	}
}
