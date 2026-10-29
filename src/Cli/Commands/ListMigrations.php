<?php

namespace Sapling\Cli\Commands;

use Sapling\App;
use Sapling\Cli\Console;
use Sapling\Db;
use Sapling\Migrations;

/**
 * Lists every migration file, and when it ran or that it's pending.
 */
final readonly class ListMigrations
{
	public const string NAME = 'migrations:list';

	public const string DESCRIPTION = 'List every migration, and when it ran';

	public function __construct(private App $app, private Db $db) {}

	/** @throws \PDOException When the migrations table can't be created or read. */
	public function __invoke(Console $console): int
	{
		$dir = $this->app->path(RunMigrations::DIR);
		$migrations = new Migrations($this->db, $dir)->all();
		if ($migrations === []) {
			$console->line('No migrations in ' . RunMigrations::DIR . '.');
			return Console::SUCCESS;
		}

		$rows = [];
		foreach ($migrations as $name => $ranAt) {
			$status = 'pending';
			if (trim((string) file_get_contents("{$dir}/{$name}")) === '') {
				$status = 'empty, skipped';
			}

			if ($ranAt !== null) {
				$status = "ran {$ranAt} UTC";
			}

			$rows[] = [$name, $status];
		}

		$console->table($rows);
		return Console::SUCCESS;
	}
}
