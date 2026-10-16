<?php

namespace Sapling\Tests\Fixtures\Scan\Http;

use Sapling\Attributes\Route;
use Sapling\Http\Request;
use Sapling\Http\Response;

#[Route('POST', '/echo-upload')]
final class EchoUpload
{
	public function __invoke(Request $request): Response
	{
		return Response::json([
			'name' => $request->data('name')->raw,
			'file' => $request->files['file']['name'] ?? null,
			'contents' => file_get_contents($request->files['file']['tmp_name'] ?? '/dev/null'),
		]);
	}
}
