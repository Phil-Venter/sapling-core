<?php

namespace Sapling\Tests\Fixtures\Container;

use Sapling\Attributes\Config;

final class Settings
{
	public function __construct(
		#[Config('greeting')]
		public string $greeting,
		#[Config('retries')]
		public int $retries = 3,
	) {}
}
