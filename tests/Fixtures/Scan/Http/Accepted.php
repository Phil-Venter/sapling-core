<?php

namespace Sapling\Tests\Fixtures\Scan\Http;

use Sapling\Attributes\Route;
use Sapling\Http\Response;

#[Route('POST', '/jobs')]
final class Accepted
{
	public function __invoke(): Response
	{
		return new Response('', 202, ['Location' => '/jobs/1']);
	}
}
