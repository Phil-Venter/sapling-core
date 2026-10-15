<?php

namespace Sapling\Tests\Fixtures\Scan\Http;

use Sapling\Attributes\Route;
use Sapling\Http\Request;
use Sapling\Http\Response;

#[Route('PUT', '/echo/:id')]
final class EchoRequest
{
	public function __invoke(Request $request): Response
	{
		return Response::json([
			'method' => $request->method,
			'path' => $request->path,
			'id' => $request->param('id')->raw,
			'name' => $request->input('name')->raw,
			'q' => $request->query('q')->raw,
			'header' => $request->header('X-Test')->raw,
			'cookie' => $request->cookie('c')->raw,
			'body' => $request->body,
			'ip' => $request->ip,
			'content_type' => $request->header('content-type')->raw,
		]);
	}
}
