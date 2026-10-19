<?php

namespace Sapling\Cli\Commands;

use Sapling\Cli\CommandList;
use Sapling\Cli\Console;

/**
 * Lists every command, grouped by the part of the name before the colon.
 */
final readonly class ListCommands
{
	public const string NAME = 'commands:list';

	public const string DESCRIPTION = 'List every command';

	public function __construct(private CommandList $commands) {}

	/**
	 * Prints every command, grouped by the part of the name before the colon.
	 */
	public function __invoke(Console $console): int
	{
		$rows = [];
		$previous = null;

		foreach ($this->commands->all() as $name => $command) {
			$domain = strstr($name, ':', true);

			if ($previous !== null && $domain !== $previous) {
				$rows[] = [];
			}

			$previous = $domain;

			// The empty first column indents each command by the gap between columns.
			$rows[] = ['', $name, $command['description']];
		}

		$console->line('Commands:');
		$console->table($rows);

		return Console::SUCCESS;
	}
}
