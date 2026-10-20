<?php

namespace Sapling\Tests\Fixtures\Container;

final class Chicken
{
	public function __construct(public Egg $egg) {}
}
