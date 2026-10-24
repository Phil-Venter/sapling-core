<?php

namespace Sapling\Tests\Fixtures\Scan\Http;

use Sapling\Attributes\Route;
use Sapling\Http\Request;
use Sapling\Http\Response;

#[Route('GET', '/to-a-file')]
final class RedirectToFile
{
	public function __invoke(Request $request): Response
	{
		return Response::redirect('file:///etc/hosts');
	}
}
