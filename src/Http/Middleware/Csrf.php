<?php

namespace Sapling\Http\Middleware;

use Sapling\Http\HttpException;
use Sapling\Http\Request;
use Sapling\Http\Response;

/**
 * Turns away a POST, PUT, PATCH or DELETE that a browser sent from another site. Browsers say where a request comes
 * from in Sec-Fetch-Site, or in Origin when they're older.
 */
final readonly class Csrf
{
	private const array WRITES = ['POST', 'PUT', 'PATCH', 'DELETE'];

	/**
	 * Sites that can send writes anyway, such as https://app.example for a front end on another domain.
	 *
	 * @param list<string> $trustedOrigins
	 */
	public function __construct(private array $trustedOrigins = []) {}

	/**
	 * @param \Closure(Request): Response $next
	 * @throws HttpException 403 for a write a browser sent from another site.
	 */
	public function __invoke(Request $request, \Closure $next): Response
	{
		if (!in_array($request->method, self::WRITES, true) || $this->allowed($request)) {
			return $next($request);
		}

		throw new HttpException(403, 'Cross-site request refused');
	}

	private function allowed(Request $request): bool
	{
		$origin = $request->header('origin')->string();

		if ($origin !== '' && in_array($origin, $this->trustedOrigins, true)) {
			return true;
		}

		$site = $request->header('sec-fetch-site')->string();

		if ($site !== '') {
			return $site === 'same-origin' || $site === 'none';
		}

		if ($origin === '') {
			return $request->cookie((string) session_name())->raw === null;
		}

		return strcasecmp($this->host($origin), $request->header('host')->string()) === 0;
	}

	private function host(string $origin): string
	{
		$host = parse_url($origin, PHP_URL_HOST);
		$port = parse_url($origin, PHP_URL_PORT);

		if (!is_string($host)) {
			return '';
		}

		if (!is_int($port)) {
			return $host;
		}

		return "{$host}:{$port}";
	}
}
