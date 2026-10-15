<?php

namespace Sapling\Tests\Fixtures\Broken;

use Sapling\Attributes\Route;
use Sapling\Http\Request;
use Sapling\Http\Response;

#[Route('GET', '/dup/:slug/')]
final class DuplicateB
{
	public function __invoke(Request $request): Response
	{
		return Response::text('broken');
	}
}
