<?php

namespace Sapling\Tests\Fixtures\Scan\Commands;

use Sapling\Attributes\Command;
use Sapling\Cli\Console;

#[Command('fail', 'Exit with code 3')]
final class Fail
{
	public function __invoke(Console $console): int
	{
		$console->error('failing on purpose');

		return 3;
	}
}
