<?php

namespace Sapling\Tests\Fixtures\Middleware;

use Closure;
use Sapling\Http\Request;
use Sapling\Http\Response;

final class Stamp
{
	public function __invoke(Request $request, Closure $next): Response
	{
		$response = $next($request);

		return $response->withHeader('X-Stamp', "saw {$response->status}");
	}
}
