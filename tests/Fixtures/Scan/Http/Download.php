<?php

namespace Sapling\Tests\Fixtures\Scan\Http;

use Sapling\Attributes\Route;
use Sapling\Http\Response;

#[Route('GET', '/download')]
final class Download
{
	public function __invoke(): Response
	{
		return Response::file(__DIR__ . '/../../files/hello.txt', 'héllo "notes".txt');
	}
}
