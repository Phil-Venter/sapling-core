<?php

namespace Sapling\Tests\Fixtures\Scan;

use RuntimeException;
use Sapling\Attributes\Route;
use Sapling\Http\Request;
use Sapling\Http\Response;

#[Route('GET', '/fails')]
final class Fails
{
	public function __invoke(Request $request): Response
	{
		throw new RuntimeException('boom <script>');
	}
}
