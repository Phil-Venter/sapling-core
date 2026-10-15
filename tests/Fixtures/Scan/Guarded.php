<?php

namespace Sapling\Tests\Fixtures\Scan;

use Sapling\Attributes\Middleware;
use Sapling\Attributes\Route;
use Sapling\Http\Request;
use Sapling\Http\Response;
use Sapling\Tests\Fixtures\Middleware\First;
use Sapling\Tests\Fixtures\Middleware\Second;
use Sapling\Tests\Fixtures\Trace;

#[Route('GET', '/guarded')]
#[Middleware(First::class)]
#[Middleware(Second::class)]
final class Guarded
{
	public function __construct(private Trace $trace) {}

	public function __invoke(Request $request): Response
	{
		$this->trace->events[] = 'action';

		return Response::text('guarded');
	}
}
