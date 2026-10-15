<?php

namespace Sapling\Tests\Fixtures\Middleware;

use Closure;
use Sapling\Http\Request;
use Sapling\Http\Response;
use Sapling\Tests\Fixtures\Trace;

final class Second
{
	public function __construct(private Trace $trace) {}

	public function __invoke(Request $request, Closure $next): Response
	{
		$this->trace->events[] = 'Second in';
		$response = $next($request);
		$this->trace->events[] = 'Second out';

		return $response;
	}
}
