<?php

namespace Sapling\Cli\Commands;

use Sapling\App;
use Sapling\Cli\Console;
use Sapling\Db;

/**
 * Lists every migration file, and when it ran or that it's pending.
 */
final readonly class ListMigrations
{
	public const string NAME = 'migrations:list';

	public const string DESCRIPTION = 'List every migration, and when it ran';

	public function __construct(private App $app, private Db $db) {}

	/**
	 * Prints each migration file in name order, and when it ran, or that it's pending or empty.
	 *
	 * @throws \PDOException When the migrations table can't be created or read.
	 */
	public function __invoke(Console $console): int
	{
		$this->db->run(RunMigrations::CREATE_TABLE);
		$files = glob($this->app->path(RunMigrations::DIR . '/*.sql'));

		if ($files === false || $files === []) {
			$console->line('No migrations in ' . RunMigrations::DIR . '.');

			return Console::SUCCESS;
		}

		$rows = [];

		foreach ($files as $file) {
			$name = basename($file);
			$ranAt = $this->db->value('SELECT ran_at FROM migrations WHERE name = ?', [$name])->string();
			$status = 'pending';

			if (trim((string) file_get_contents($file)) === '') {
				$status = 'empty, so migrations:run skips it';
			}

			if ($ranAt !== '') {
				$status = "ran {$ranAt} UTC";
			}

			$rows[] = [$name, $status];
		}

		$console->table($rows);

		return Console::SUCCESS;
	}
}
