<?php

namespace Sapling\Tests\Fixtures\Middleware;

use Closure;
use Sapling\Http\Request;
use Sapling\Http\Response;
use Sapling\Tests\Fixtures\Trace;

final class GlobalTwo
{
	public function __construct(private Trace $trace) {}

	public function __invoke(Request $request, Closure $next): Response
	{
		$this->trace->events[] = 'GlobalTwo in';
		$response = $next($request);
		$this->trace->events[] = 'GlobalTwo out';

		return $response;
	}
}
