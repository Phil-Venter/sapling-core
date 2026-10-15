<?php

namespace Sapling\Http;

use JsonException;
use NoDiscard;
use RuntimeException;
use Sapling\Data;
use Sapling\Value;
use Throwable;

/**
 * An HTTP response, with its status, headers and a body or a file.
 */
final class Response
{
	public private(set) ?string $file = null;

	/**
	 * Use html(), text(), json(), redirect() or file() for the common cases, which also set the Content-Type.
	 *
	 * @param array<string, string|list<string>> $headers
	 */
	public function __construct(
		public private(set) string $body = '',
		public private(set) int    $status = 200,
		public private(set) array  $headers = [],
	) {}

	/**
	 * An HTML response.
	 */
	public static function html(string $html, int $status = 200): self
	{
		return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
	}

	/**
	 * A plain text response.
	 */
	public static function text(string $text, int $status = 200): self
	{
		return new self($text, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
	}

	/**
	 * A JSON response.
	 */
	public static function json(mixed $data, int $status = 200): self
	{
		$json = Data::toJson($data);

		return new self($json, $status, ['Content-Type' => 'application/json']);
	}

	/**
	 * A redirect, 302 unless you pass another status.
	 */
	public static function redirect(string $url, int $status = 302): self
	{
		return new self('', $status, ['Location' => $url]);
	}

	/**
	 * A download of the file, under its own name or $name.
	 *
	 * @throws RuntimeException When the file is missing.
	 */
	public static function file(string $path, ?string $name = null): self
	{
		if (!is_file($path)) {
			throw new RuntimeException("File not found: {$path}");
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
	 * A plain text response with the exception's status, message and headers.
	 */
	public static function fromHttpException(HttpException $e): self
	{
		return new self($e->getMessage(), $e->status, [
			'Content-Type' => 'text/plain; charset=utf-8',
			...$e->headers,
		]);
	}

	/**
	 * A 500 for a crash. In debug mode it's the debug page, otherwise a plain Internal Server Error.
	 */
	public static function exception(Throwable $e, bool $debug, string $root = ''): self
	{
		if (!$debug) {
			return self::text('Internal Server Error', 500);
		}

		return self::html(DebugPage::render($e, $root), 500);
	}

	/**
	 * The body decoded by its Content-Type, as JSON, XML or a form, as in data()->get('name')->string(). An empty body
	 * gives an empty Value, and any other type gives the body as it is.
	 *
	 * @throws JsonException When a JSON body is invalid.
	 * @throws RuntimeException When an XML body is invalid.
	 */
	public function data(): Value
	{
		$type = $this->header('content-type');

		$firstOfList = $type->get(0)->string();
		$contentType = $type->string($firstOfList);

		return new Value(Data::decode($this->body, $contentType));
	}

	/**
	 * A header, whatever the case of its name, as in header('content-type')->string(). A header a reply sent more
	 * than once is a list, read with array().
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
	 * A copy with the header set, replacing it whatever the case of its name. The original is unchanged.
	 *
	 * @param string|list<string> $value
	 */
	#[NoDiscard('the original response is unchanged')]
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
	 * Sends the status, headers and body. With body: false, only the status and headers.
	 */
	public function send(bool $body = true): void
	{
		http_response_code($this->status);

		foreach ($this->headers as $name => $values) {
			$replace = strcasecmp($name, 'Set-Cookie') !== 0;

			foreach ((array) $values as $value) {
				header("{$name}: {$value}", $replace);
				$replace = false;
			}
		}

		$bodiless = $this->status < 200 || $this->status === 204 || $this->status === 304;

		if (!$body || $bodiless) {
			return;
		}

		if ($this->file !== null) {
			$this->sendFile($this->file);

			return;
		}

		echo $this->body;
	}

	private function sendFile(string $path): void
	{
		$handle = fopen($path, 'rb');

		while (!feof($handle)) {
			echo fread($handle, 65_536);
		}

		fclose($handle);
	}
}
