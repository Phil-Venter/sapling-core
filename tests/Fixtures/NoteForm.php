<?php

namespace Sapling\Tests\Fixtures;

use Sapling\Attributes\Route;
use Sapling\Http\Request;
use Sapling\Http\Response;

#[Route('GET', '/notes/form')]
#[Route('GET', '/notes/form/:id')]
final class NoteForm
{
	public function __invoke(Request $request): Response
	{
		return Response::text("form for {$request->param('id')->string('a new note')}");
	}
}
