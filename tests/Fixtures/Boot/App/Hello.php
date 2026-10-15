<?php

namespace Sapling\Tests\Fixtures\Boot\App;

use Sapling\Attributes\Route;
use Sapling\Config;
use Sapling\Http\Request;
use Sapling\Http\Response;
use Sapling\Template;

#[Route('GET', '/hello')]
final class Hello
{
	public function __construct(private Config $config, private Template $view) {}

	public function __invoke(Request $request): Response
	{
		$mode = $this->config->get('debug', false) ? 'dev' : 'production';

		return Response::html($this->view->render('hello', ['mode' => $mode]));
	}
}
