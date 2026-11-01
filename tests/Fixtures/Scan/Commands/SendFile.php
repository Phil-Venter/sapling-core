<?php

namespace Sapling\Tests\Fixtures\Scan\Commands;

use Sapling\Attributes\Command;
use Sapling\Cli\Console;
use Sapling\Http\Response;

#[Command('send-file', 'Send the file at arg 0')]
final class SendFile
{
	public function __invoke(Console $console): int
	{
		Response::file($console->arg(0)->string())->send();

		return 0;
	}
}
