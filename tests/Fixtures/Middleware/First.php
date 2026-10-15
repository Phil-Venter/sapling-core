<?php

namespace Sapling\Tests\Fixtures\Middleware;

use Closure;
use Sapling\Http\Request;
use Sapling\Http\Response;
use Sapling\Tests\Fixtures\Trace;

final class First
{
	public function __construct(private Trace $trace) {}

	public function __invoke(Request $request, Closure $next): Response
	{
		$this->trace->events[] = 'First in';
		$response = $next($request);
		$this->trace->events[] = 'First out';

		return $response;
	}
}
