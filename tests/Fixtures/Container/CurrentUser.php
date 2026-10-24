<?php

namespace Sapling\Tests\Fixtures\Container;

final readonly class CurrentUser
{
	public function __construct(public string $name) {}
}
