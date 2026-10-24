<?php

namespace Sapling\Tests\Fixtures\Container;

use Sapling\Http\Request;
use Sapling\Http\Response;

final readonly class Profile
{
	public function __construct(private CurrentUser $user) {}

	public function __invoke(Request $request): Response
	{
		return Response::text("Signed in as {$this->user->name}");
	}
}
