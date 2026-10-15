<?php

namespace Sapling\Tests\Fixtures\Scan\Commands;

use Sapling\Attributes\Command;
use Sapling\Cli\Console;

#[Command('crash', 'Trigger a warning')]
final class Crash
{
	public function __invoke(Console $console): int
	{
		$items = [];

		return $items[0];
	}
}
