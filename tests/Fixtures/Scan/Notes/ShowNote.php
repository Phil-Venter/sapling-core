<?php

namespace Sapling\Tests\Fixtures\Scan\Notes;

use Sapling\Attributes\Route;
use Sapling\Http\Request;
use Sapling\Http\Response;

#[Route('GET', '/notes/:id')]
final class ShowNote
{
	public function __invoke(Request $request): Response
	{
		return Response::text("note {$request->param('id')->string()}");
	}
}
