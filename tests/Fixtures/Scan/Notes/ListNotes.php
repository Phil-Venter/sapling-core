<?php

namespace Sapling\Tests\Fixtures\Scan\Notes;

use Sapling\Attributes\Route;
use Sapling\Http\Request;
use Sapling\Http\Response;

#[Route('GET', '/notes')]
final class ListNotes
{
	public function __invoke(Request $request): Response
	{
		return Response::text('notes');
	}
}
