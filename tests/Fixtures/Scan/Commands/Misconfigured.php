<?php

namespace Sapling\Tests\Fixtures\Scan\Commands;

use InvalidArgumentException;
use Sapling\Attributes\Command;
use Sapling\Cli\Console;

#[Command('misconfigured', 'Throw an InvalidArgumentException, as a bug does')]
final class Misconfigured
{
	public function __invoke(Console $console): int
	{
		throw new InvalidArgumentException('app_key must be 32 bytes, base64-encoded');
	}
}
