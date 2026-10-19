<?php

namespace Sapling\Tests\Fixtures\Scan\Commands;

use Sapling\Attributes\Command;
use Sapling\Cli\Console;

#[Command('hello', 'Ask for a name and say hello')]
final class Hello
{
	public function __invoke(Console $console): int
	{
		$name = $console->ask('Name?')->string('nobody');
		$console->line("Hello {$name}.");

		return 0;
	}
}
