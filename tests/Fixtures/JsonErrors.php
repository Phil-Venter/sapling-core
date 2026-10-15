<?php

namespace Sapling\Tests\Fixtures;

use Sapling\Data;
use Sapling\Http\HttpException;
use Sapling\Http\Request;
use Sapling\Http\Response;

final class JsonErrors
{
	public function __construct(private Trace $trace) {}

	public function __invoke(HttpException $e, Request $request): Response
	{
		$this->trace->events[] = "{$request->method} {$request->path}";
		$json = Data::toJson(['error' => $e->getMessage()]);

		return new Response($json, $e->status, ['Content-Type' => 'application/json', ...$e->headers]);
	}
}
