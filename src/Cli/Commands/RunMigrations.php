<?php

namespace Sapling\Cli\Commands;

use Sapling\App;
use Sapling\Cli\Console;
use Sapling\Db;
use Sapling\Migrations;

/**
 * Runs new database/migrations/*.sql files in name order, each in a transaction, and records them.
 */
final readonly class RunMigrations
{
	public const string NAME = 'migrations:run';

	public const string DESCRIPTION = 'Run new database/migrations/*.sql files in name order';

	public const string DIR = 'database/migrations';

	public function __construct(private App $app, private Db $db) {}

	/**
	 * @throws \RuntimeException When a migration fails. It's rolled back, and the ones before it stay recorded.
	 * @throws \Throwable
	 */
	public function __invoke(Console $console): int
	{
		$migrations = new Migrations($this->db, $this->app->path(self::DIR));
		$ran = $migrations->run();

		foreach ($ran as $name) {
			$console->line("Migrated {$name}");
		}

		// run() leaves only empty files pending.
		foreach ($migrations->all() as $name => $ranAt) {
			if ($ranAt !== null) {
				continue;
			}

			$console->line("Skipped {$name}, it's empty");
		}

		if ($ran === []) {
			$console->line('Nothing to migrate.');
		}

		return Console::SUCCESS;
	}
}
