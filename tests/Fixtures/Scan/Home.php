<?php

namespace Sapling\Tests\Fixtures\Scan;

use Sapling\Attributes\Route;
use Sapling\Http\Request;
use Sapling\Http\Response;

#[Route('GET', '/')]
final class Home
{
	public function __invoke(Request $request): Response
	{
		return Response::text('home');
	}
}
