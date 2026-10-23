<?php

namespace Sapling\Tests\Fixtures\Scan\Http;

use Sapling\Attributes\Route;
use Sapling\Http\Request;
use Sapling\Http\Response;

#[Route('GET', '/cookies')]
final class Cookies
{
	public function __invoke(Request $request): Response
	{
		return Response::text('cookies')->withHeader('Set-Cookie', ['a=1; Path=/', 'b=2; Path=/']);
	}
}
