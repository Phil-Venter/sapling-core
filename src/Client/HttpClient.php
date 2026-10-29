<?php

namespace Sapling\Client;

/**
 * Sends ClientRequests to other HTTP services through curl.
 */
final class HttpClient
{
	/** @var (\Closure(ClientRequest): ClientResponse)|null */
	private ?\Closure $fake = null;

	/**
	 * Requests give up after $timeout seconds.
	 */
	public function __construct(private readonly int $timeout = 30) {}

	/**
	 * A redirect or an error status is a normal reply. A connection that fails or times out gives status 0, with the error as the body.
	 */
	public function send(ClientRequest $request): ClientResponse
	{
		if ($this->fake !== null) {
			return ($this->fake)($request);
		}

		$handle = $this->handle($request);
		$content = (string) curl_exec($handle);
		return $this->response($request, $handle, $content);
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
		if ($this->fake !== null) {
			return array_map($this->fake, $requests);
		}

		$multi = curl_multi_init();
		curl_multi_setopt($multi, CURLMOPT_MAX_HOST_CONNECTIONS, 6);

		$pairs = [];
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
			$content = (string) curl_multi_getcontent($handle);
			$responses[$key] = $this->response($request, $handle, $content);
		}

		return $responses;
	}

	/**
	 * Answers every request with $answer instead of curl, for tests.
	 *
	 * @param \Closure(ClientRequest): ClientResponse $answer
	 */
	public function fake(\Closure $answer): self
	{
		$this->fake = $answer;
		return $this;
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
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
		]);

		$hasBody = $request->body !== '' && $request->body !== [];

		// Some servers refuse a POST, PUT or PATCH without a Content-Length, so an empty one still sends 0.
		if ($hasBody || in_array($request->method, ['POST', 'PUT', 'PATCH'], true)) {
			curl_setopt($handle, CURLOPT_POSTFIELDS, $request->body);
		}

		return $handle;
	}

	private function response(ClientRequest $request, \CurlHandle $handle, string $content): ClientResponse
	{
		if (curl_errno($handle) !== 0) {
			$error = "{$request->method} {$request->url} failed: " . curl_error($handle);
			return new ClientResponse($error, 0, []);
		}

		$size = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
		$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		$head = trim(substr($content, 0, $size));
		$lastBlock = strrpos($head, "\r\n\r\n");
		$received = [];
		if ($lastBlock !== false) {
			$head = substr($head, $lastBlock + 4);
		}

		foreach (explode("\r\n", $head) as $line) {
			$colon = strpos($line, ':');
			if ($colon === false) {
				continue;
			}

			$name = strtolower(trim(substr($line, 0, $colon)));
			$received[$name][] = trim(substr($line, $colon + 1));
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
