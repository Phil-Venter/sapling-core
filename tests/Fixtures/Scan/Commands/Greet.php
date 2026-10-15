<?php

namespace Sapling\Tests\Fixtures\Scan\Commands;

use Sapling\Attributes\Command;
use Sapling\Cli\Console;

#[Command('greet', 'Say hello to arg 0')]
final class Greet
{
	public function __invoke(Console $console): int
	{
		$name = $console->arg(0)->string('world');
		$times = $console->option('times')->int(1);
		$end = $console->flag('shout') ? '!' : '.';

		for ($i = 0; $i < $times; $i++) {
			$console->line("Hello {$name}{$end}");
		}

		return 0;
	}
}
