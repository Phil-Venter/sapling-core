<?php

namespace Sapling\Tests\Fixtures\Scan\Notes;

use Sapling\Attributes\Route;
use Sapling\Http\Request;
use Sapling\Http\Response;

#[Route('POST', '/notes')]
final class StoreNote
{
	public function __invoke(Request $request): Response
	{
		return Response::redirect('/notes');
	}
}
