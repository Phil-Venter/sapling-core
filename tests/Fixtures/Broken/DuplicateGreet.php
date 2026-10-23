<?php

namespace Sapling\Tests\Fixtures\Broken;

use Sapling\Attributes\Command;
use Sapling\Cli\Console;

#[Command('greet')]
final class DuplicateGreet
{
	public function __invoke(Console $console): int
	{
		return Console::SUCCESS;
	}
}
