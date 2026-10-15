<?php

namespace Sapling\Cli\Commands;

use Sapling\App;
use Sapling\Cli\Console;

/**
 * Creates an empty migration, named by the UTC time and the name you give.
 */
final readonly class NewMigration
{
	public const string NAME = 'migrations:new';

	public const string DESCRIPTION = 'Create an empty, timestamped migration file';

	public function __construct(private App $app) {}

	public function __invoke(Console $console): int
	{
		$name = strtolower($console->arg(0)->string());
		$name = trim((string) preg_replace('/[^a-z0-9]+/', '_', $name), '_');

		if ($name === '') {
			$console->error('Usage: php bin/console migrations:new <name>');

			return Console::FAILURE;
		}

		$dir = $this->app->path(RunMigrations::DIR);
		$file = gmdate('YmdHis') . "-{$name}.sql";

		if (!is_dir($dir)) {
			mkdir($dir, recursive: true);
		}

		if (file_exists("{$dir}/{$file}")) {
			$console->error("{$file} already exists");

			return Console::FAILURE;
		}

		file_put_contents("{$dir}/{$file}", '');
		$console->line('Created ' . RunMigrations::DIR . "/{$file}");

		return Console::SUCCESS;
	}
}
