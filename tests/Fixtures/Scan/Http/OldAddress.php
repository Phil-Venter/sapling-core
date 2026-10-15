<?php

namespace Sapling\Tests\Fixtures\Scan\Http;

use Sapling\Attributes\Route;
use Sapling\Http\Request;
use Sapling\Http\Response;

#[Route('GET', '/old-address')]
final class OldAddress
{
	public function __invoke(Request $request): Response
	{
		return Response::redirect('/notes', 301);
	}
}
