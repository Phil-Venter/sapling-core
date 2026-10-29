<?php

namespace Sapling\Http\Middleware;

use Sapling\Http\Request;
use Sapling\Http\Response;

/**
 * Sets the browser security headers and a content policy, unless the response already has them. It allows inline styles but not inline scripts.
 */
final readonly class SecurityHeaders
{
	public const array POLICY = [
		'default-src' => "'self'",
		'style-src' => "'self' https: 'unsafe-inline'",
		'img-src' => "'self' https: data:",
		'font-src' => "'self' https: data:",
		'base-uri' => "'none'",
		'form-action' => "'self'",
		'frame-ancestors' => "'none'",
	];

	/**
	 * The content_policy setting. Each directive replaces the default of the same name, such as style-src.
	 *
	 * @param array<string, string> $policy
	 */
	public function __construct(private array $policy = []) {}

	/** @param \Closure(Request): Response $next */
	public function __invoke(Request $request, \Closure $next): Response
	{
		$policy = [];
		foreach ([...self::POLICY, ...$this->policy] as $name => $sources) {
			$policy[] = trim("{$name} {$sources}");
		}

		$response = $next($request);
		$headers = [
			'X-Frame-Options' => 'DENY',
			'X-Content-Type-Options' => 'nosniff',
			'Referrer-Policy' => 'same-origin',
			'Content-Security-Policy' => implode('; ', $policy),
		];

		foreach ($headers as $name => $value) {
			if ($response->header($name)->raw !== null) {
				continue;
			}

			$response = $response->withHeader($name, $value);
		}

		return $response;
	}
}
