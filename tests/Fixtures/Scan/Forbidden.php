<?php

namespace Sapling\Tests\Fixtures\Scan;

use Sapling\Attributes\Route;
use Sapling\Http\HttpException;
use Sapling\Http\Request;
use Sapling\Http\Response;

#[Route('GET', '/forbidden')]
final class Forbidden
{
	public function __invoke(Request $request): Response
	{
		throw new HttpException(403);
	}
}
