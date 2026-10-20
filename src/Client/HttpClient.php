<?php

namespace Sapling\Client;

/**
 * Sends ClientRequests to other HTTP services through curl.
 */
final readonly class HttpClient
{
	/**
	 * Requests give up after $timeout seconds.
	 */
	public function __construct(private int $timeout = 30) {}

	/**
	 * Follows redirects for GET and HEAD. An error status is a normal reply. A connection that fails or times out
	 * gives status 0, with the error as the body.
	 */
	public function send(ClientRequest $request): ClientResponse
	{
		$handle = $this->handle($request);
		$content = curl_exec($handle);

		if (curl_errno($handle) !== 0) {
			return $this->failure($request, $handle);
		}

		return $this->response($handle, (string) $content);
	}

	/**
	 * Sends them at once. Each reply keeps its request's key. A failed connection gives status 0, as with send().
	 *
	 * @template K of array-key
	 * @param array<K, ClientRequest> $requests
	 * @return array<K, ClientResponse>
	 */
	public function sendAll(array $requests): array
	{
		$multi = curl_multi_init();
		$pairs = [];

		curl_multi_setopt($multi, CURLMOPT_MAX_HOST_CONNECTIONS, 6);

		foreach ($requests as $key => $request) {
			$handle = $this->handle($request);
			$pairs[$key] = [$request, $handle];
			curl_multi_add_handle($multi, $handle);
		}

		$running = 0;

		do {
			$status = curl_multi_exec($multi, $running);

			if ($running > 0) {
				curl_multi_select($multi);
			}
		} while ($running > 0 && $status === CURLM_OK);

		// Reading each request's message fills in its curl_errno() and curl_error().
		foreach ($pairs as $_) {
			curl_multi_info_read($multi);
		}

		$responses = [];

		foreach ($pairs as $key => [$request, $handle]) {
			if (curl_errno($handle) !== 0) {
				$responses[$key] = $this->failure($request, $handle);
				continue;
			}

			$responses[$key] = $this->response($handle, (string) curl_multi_getcontent($handle));
		}

		return $responses;
	}

	private function handle(ClientRequest $request): \CurlHandle
	{
		$headers = $request->headers;

		// curl labels a string body as a form when it has no type, and an empty header line stops it.
		if (is_string($request->body) && !array_key_exists('content-type', $headers)) {
			$headers['content-type'] = '';
		}

		$lines = [];

		foreach ($headers as $name => $value) {
			$lines[] = "{$name}: {$value}";
		}

		$handle = curl_init($request->url);

		curl_setopt_array($handle, [
			CURLOPT_CUSTOMREQUEST => $request->method,
			CURLOPT_HTTPHEADER => $lines,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => true,
			CURLOPT_TIMEOUT => $this->timeout,
			CURLOPT_ENCODING => '',
			CURLOPT_NOBODY => $request->method === 'HEAD',
			CURLOPT_FOLLOWLOCATION => in_array($request->method, ['GET', 'HEAD'], true),
			CURLOPT_MAXREDIRS => 5,
			// Not the _STR options, which need libcurl 7.85, newer than Ubuntu 22.04 has.
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
		]);

		$hasBody = $request->body !== '' && $request->body !== [];

		if ($hasBody) {
			curl_setopt($handle, CURLOPT_POSTFIELDS, $request->body);
		}

		// Some servers refuse a POST, PUT or PATCH without a Content-Length, so an empty one still sends 0.
		if (!$hasBody && in_array($request->method, ['POST', 'PUT', 'PATCH'], true)) {
			curl_setopt($handle, CURLOPT_POSTFIELDS, '');
		}

		return $handle;
	}

	private function failure(ClientRequest $request, \CurlHandle $handle): ClientResponse
	{
		$error = "{$request->method} {$request->url} failed: " . curl_error($handle);

		return new ClientResponse($error, 0, []);
	}

	private function response(\CurlHandle $handle, string $content): ClientResponse
	{
		$size = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
		$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

		// Every redirect adds a block of headers, and the last block is the final reply's.
		$blocks = explode("\r\n\r\n", trim(substr($content, 0, $size)));
		$lines = explode("\r\n", $blocks[count($blocks) - 1] ?? '');
		$received = [];

		foreach ($lines as $line) {
			if (!str_contains($line, ':')) {
				continue;
			}

			$parts = explode(':', $line, 2);
			$name = strtolower(trim($parts[0]));
			$received[$name][] = trim($parts[1] ?? '');
		}

		$headers = [];

		foreach ($received as $name => $values) {
			if (count($values) === 1) {
				$values = $values[0];
			}

			$headers[$name] = $values;
		}

		return new ClientResponse(substr($content, $size), $status, $headers);
	}
}
