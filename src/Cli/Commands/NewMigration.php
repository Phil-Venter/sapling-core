<?php

namespace Sapling\Cli\Commands;

use Sapling\App;
use Sapling\Cli\Console;
use Sapling\Cli\UsageException;

/**
 * Creates an empty migration, named by the UTC time and the name you give.
 */
final readonly class NewMigration
{
	public const string NAME = 'migrations:new';

	public const string DESCRIPTION = 'Create an empty, timestamped migration file';

	public function __construct(private App $app) {}

	/**
	 * add notes and "add notes" both give add_notes.
	 *
	 * @throws UsageException When the name has nothing usable in it.
	 */
	public function __invoke(Console $console): int
	{
		$name = strtolower(implode(' ', $console->args));
		$name = trim((string) preg_replace('/[^a-z0-9]+/', '_', $name), '_');
		if ($name === '') {
			throw new UsageException('Usage: php bin/console migrations:new <name>');
		}

		$dir = $this->app->path(RunMigrations::DIR);
		$file = gmdate('YmdHis') . "-{$name}.sql";
		if (!is_dir($dir)) {
			mkdir($dir, recursive: true);
		}

		file_put_contents("{$dir}/{$file}", '');
		$console->line('Created ' . RunMigrations::DIR . "/{$file}");
		return Console::SUCCESS;
	}
}
