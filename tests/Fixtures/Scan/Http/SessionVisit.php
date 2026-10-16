<?php

namespace Sapling\Tests\Fixtures\Scan\Http;

use Sapling\Attributes\Route;
use Sapling\Http\Response;
use Sapling\Http\Session;

#[Route('GET', '/visit')]
final class SessionVisit
{
	public function __invoke(): Response
	{
		$session = new Session(3600);
		$visits = $session->get('visits')->int() + 1;
		$session->set('visits', $visits);

		return Response::text((string) $visits);
	}
}
