<?php

namespace Sapling\Tests\Fixtures\Container;

final class NeedsName
{
	public function __construct(public string $name) {}
}
