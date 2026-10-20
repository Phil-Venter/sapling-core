<?php

namespace Sapling\Tests\Fixtures\Container;

final class Egg
{
	public function __construct(public Chicken $chicken) {}
}
