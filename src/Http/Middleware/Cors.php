<?php

namespace Sapling\Http\Middleware;

use Closure;
use InvalidArgumentException;
use Sapling\Http\Request;
use Sapling\Http\Response;

/**
 * Lets the sites you list call the app from a browser. It answers preflights and adds the CORS headers.
 */
final readonly class Cors
{
	private bool $anyOrigin;

	/**
	 * Origins are exact, such as https://app.example, or ['*'] for any site. A setting that's unsafe, or could
	 * never match, throws InvalidArgumentException.
	 *
	 * @param list<string> $origins
	 * @param list<string> $exposeHeaders
	 */
	public function __construct(
		private array $origins,
		private bool $allowCredentials = false,
		private array $exposeHeaders = [],
	) {
		$this->anyOrigin = in_array('*', $origins, true);

		foreach ($origins as $origin) {
			$this->checkOrigin($origin);
		}

		if ($allowCredentials && in_array('*', $exposeHeaders, true)) {
			throw new InvalidArgumentException('cors_expose_headers cannot be * with cors_credentials');
		}
	}

	/** @param Closure(Request): Response $next */
	public function __invoke(Request $request, Closure $next): Response
	{
		$origin = $request->header('origin')->string();
		$method = $request->header('access-control-request-method')->string();

		if ($origin !== '' && $request->method === 'OPTIONS' && $method !== '') {
			return $this->preflight($request, $origin, $method);
		}

		$allowed = null;

		if ($origin !== '') {
			$allowed = $this->allowedOrigin($origin);
		}

		$response = $next($request);

		foreach ($this->corsHeaders($allowed, $response->headers) as $name => $value) {
			$response = $response->withHeader($name, $value);
		}

		return $response;
	}

	private function checkOrigin(string $origin): void
	{
		if ($origin === '*') {
			if ($this->allowCredentials) {
				throw new InvalidArgumentException('cors_credentials needs cors_origins to list sites, not *');
			}

			return;
		}

		if ($origin === 'null') {
			throw new InvalidArgumentException('cors_origins cannot list null, any site can send it');
		}

		$parts = [];

		if (preg_match('#^(https?)://([a-z0-9.-]+|\[[0-9a-f:]+\])(:[0-9]{1,5})?$#', $origin, $parts) !== 1) {
			throw new InvalidArgumentException("cors_origins needs scheme://host[:port] in lowercase, got {$origin}");
		}

		$scheme = $parts[1] ?? '';
		$host = $parts[2] ?? '';
		$local = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);

		if ($this->allowCredentials && $scheme === 'http' && !$local) {
			throw new InvalidArgumentException("cors_credentials needs https origins or localhost, got {$origin}");
		}
	}

	private function preflight(Request $request, string $origin, string $method): Response
	{
		$allowed = $this->allowedOrigin($origin);
		$vary = 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers';

		if ($allowed === null) {
			return new Response('', 403, ['Vary' => $vary]);
		}

		$headers = [
			'Access-Control-Allow-Origin' => $allowed,
			'Access-Control-Allow-Methods' => $method,
			'Access-Control-Max-Age' => '7200',
			'Vary' => $vary,
		];

		$requested = $request->header('access-control-request-headers')->string();

		if ($requested !== '') {
			$headers['Access-Control-Allow-Headers'] = $requested;
		}

		if ($this->allowCredentials) {
			$headers['Access-Control-Allow-Credentials'] = 'true';
		}

		return new Response('', 204, $headers);
	}

	private function allowedOrigin(string $origin): ?string
	{
		if ($this->anyOrigin) {
			return '*';
		}

		if (in_array($origin, $this->origins, true)) {
			return $origin;
		}

		return null;
	}

	/**
	 * @param array<string, string|list<string>> $existing
	 * @return array<string, string>
	 */
	private function corsHeaders(?string $allowed, array $existing): array
	{
		$headers = [];

		// On every response, or a cache could serve one origin's answer to another.
		if (!$this->anyOrigin) {
			$headers['Vary'] = 'Origin';

			foreach ($existing as $name => $value) {
				if (strcasecmp($name, 'Vary') !== 0) {
					continue;
				}

				$headers['Vary'] = implode(', ', (array) $value) . ', Origin';
			}
		}

		if ($allowed === null) {
			return $headers;
		}

		$headers['Access-Control-Allow-Origin'] = $allowed;

		if ($this->allowCredentials) {
			$headers['Access-Control-Allow-Credentials'] = 'true';
		}

		if ($this->exposeHeaders !== []) {
			$headers['Access-Control-Expose-Headers'] = implode(', ', $this->exposeHeaders);
		}

		return $headers;
	}
}
