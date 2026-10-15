<?php

namespace Sapling\Tests\Fixtures\Scan\Notes;

use Sapling\Attributes\Route;
use Sapling\Http\Request;
use Sapling\Http\Response;

#[Route('DELETE', '/notes/:id')]
final class DeleteNote
{
	public function __invoke(Request $request): Response
	{
		return Response::text("deleted {$request->param('id')->string()}");
	}
}
