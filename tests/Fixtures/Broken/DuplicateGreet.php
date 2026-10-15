<?php

namespace Sapling\Tests\Fixtures\Broken;

use Sapling\Attributes\Command;
use Sapling\Http\Request;
use Sapling\Http\Response;

#[Command('greet')]
final class DuplicateGreet
{
	public function __invoke(Request $request): Response
	{
		return Response::text('broken');
	}
}
