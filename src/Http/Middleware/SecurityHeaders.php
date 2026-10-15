<?php

namespace Sapling\Http\Middleware;

use Closure;
use Sapling\Http\Request;
use Sapling\Http\Response;

/**
 * Sets the browser security headers and a content policy. Directives you pass replace the defaults, as in new
 * SecurityHeaders(['script-src' => "'self' https://unpkg.com"]).
 */
final readonly class SecurityHeaders
{
	public const array POLICY = [
		'default-src' => "'self'",
		'style-src' => "'self' https:",
		'img-src' => "'self' https: data:",
		'font-src' => "'self' https: data:",
		'base-uri' => "'none'",
		'form-action' => "'self'",
		'frame-ancestors' => "'none'",
	];

	/**
	 * Directives to change, such as script-src. Each replaces the default of the same name.
	 *
	 * @param array<string, string> $policy
	 */
	public function __construct(private array $policy = []) {}

	/** @param Closure(Request): Response $next */
	public function __invoke(Request $request, Closure $next): Response
	{
		$policy = [];

		foreach ([...self::POLICY, ...$this->policy] as $name => $sources) {
			$policy[] = trim("{$name} {$sources}");
		}

		return $next($request)
			->withHeader('X-Frame-Options', 'DENY')
			->withHeader('X-Content-Type-Options', 'nosniff')
			->withHeader('Referrer-Policy', 'same-origin')
			->withHeader('Content-Security-Policy', implode('; ', $policy));
	}
}
