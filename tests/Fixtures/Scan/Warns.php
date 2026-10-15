<?php

namespace Sapling\Tests\Fixtures\Scan;

use Sapling\Attributes\Route;
use Sapling\Http\Request;
use Sapling\Http\Response;

#[Route('GET', '/warns')]
final class Warns
{
	public function __invoke(Request $request): Response
	{
		$notes = [];
		$first = $notes[0]; // "Undefined array key 0" warning: run() turns it into an ErrorException

		return Response::text('kept going after a warning: ' . var_export($first, true));
	}
}
