<?php

namespace Sapling\Cli\Commands;

use Sapling\App;
use Sapling\Cli\Console;
use Sapling\Db;

/**
 * Runs new database/migrations/*.sql files in name order, each in a transaction, and records them.
 */
final readonly class RunMigrations
{
	public const string NAME = 'migrations:run';

	public const string DESCRIPTION = 'Run new database/migrations/*.sql files in name order';

	public const string DIR = 'database/migrations';

	/** Creates the table that records which migrations have run, unless it exists. */
	public const string CREATE_TABLE = <<<SQL
			CREATE TABLE IF NOT EXISTS migrations (
				name VARCHAR(255) PRIMARY KEY,
				ran_at VARCHAR(19) NOT NULL
			)
		SQL;

	public function __construct(private App $app, private Db $db) {}

	/**
	 * Runs the migrations that haven't run yet.
	 *
	 * @throws \PDOException When a migration's SQL fails. Its transaction is rolled back.
	 * @throws \Throwable
	 */
	public function __invoke(Console $console): int
	{
		$this->db->run(self::CREATE_TABLE);
		$files = glob($this->app->path(self::DIR . '/*.sql'));
		$ran = 0;

		if ($files === false) {
			$files = [];
		}

		foreach ($files as $file) {
			$name = basename($file);

			if ($this->db->value('SELECT 1 FROM migrations WHERE name = ?', [$name])->raw !== null) {
				continue;
			}

			$sql = (string) file_get_contents($file);

			if (trim($sql) === '') {
				$console->line("Skipped {$name}, it's empty");
				continue;
			}

			$this->db->transaction(
				/** @throws \PDOException */
				function (Db $db) use ($sql, $name): void {
					$db->pdo->exec($sql);
					$db->run('INSERT INTO migrations (name, ran_at) VALUES (?, ?)', [$name, gmdate('Y-m-d H:i:s')]);
				},
			);

			$console->line("Migrated {$name}");
			$ran++;
		}

		$summary = "Ran {$ran} migrations.";

		if ($ran === 1) {
			$summary = 'Ran 1 migration.';
		}

		if ($ran === 0) {
			$summary = 'Nothing to migrate.';
		}

		$console->line($summary);

		return Console::SUCCESS;
	}
}
