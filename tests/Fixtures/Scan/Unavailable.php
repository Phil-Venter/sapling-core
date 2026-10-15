<?php

namespace Sapling\Tests\Fixtures\Scan;

use Sapling\Attributes\Route;
use Sapling\Http\HttpException;
use Sapling\Http\Request;
use Sapling\Http\Response;

#[Route('GET', '/unavailable')]
final class Unavailable
{
	public function __invoke(Request $request): Response
	{
		throw new HttpException(503, 'Down for maintenance');
	}
}
