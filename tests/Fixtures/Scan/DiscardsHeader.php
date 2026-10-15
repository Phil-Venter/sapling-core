<?php

namespace Sapling\Tests\Fixtures\Scan;

use Sapling\Attributes\Route;
use Sapling\Http\Response;

#[Route('GET', '/discards-header')]
final class DiscardsHeader
{
	public function __invoke(): Response
	{
		$response = Response::text('no header');
		$response->withHeader('X-Lost', 'yes');

		return $response;
	}
}
