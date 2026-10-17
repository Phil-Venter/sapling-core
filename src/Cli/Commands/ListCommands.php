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
		$commands = $this->commands->all();
		$width = 0;

		foreach (array_keys($commands) as $name) {
			$width = max($width, strlen($name));
		}

		$console->line('Commands:');
		$previous = null;

		foreach ($commands as $name => $command) {
			$domain = strstr($name, ':', true);

			if ($previous !== null && $domain !== $previous) {
				$console->line();
			}

			$previous = $domain;
			$console->line('  ' . str_pad($name, $width) . '  ' . $command['description']);
		}

		return Console::SUCCESS;
	}
}
