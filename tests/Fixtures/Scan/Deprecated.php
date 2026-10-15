<?php

namespace Sapling\Tests\Fixtures\Scan;

use Sapling\Attributes\Route;
use Sapling\Http\Request;
use Sapling\Http\Response;

#[Route('GET', '/deprecated')]
final class Deprecated
{
	public function __invoke(Request $request): Response
	{
		trigger_error('this old call goes away in the next PHP', E_USER_DEPRECATED);

		return Response::text('still works');
	}
}
