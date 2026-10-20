<?php

namespace Sapling\Cli\Commands;

use Sapling\App;
use Sapling\Cli\Console;
use Sapling\Db;
use Sapling\Value;

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
	 * @throws \RuntimeException When a migration leaves a row whose foreign key points nowhere. It's rolled back too.
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

			$this->apply($name, $sql);
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

	/** @throws \Throwable */
	private function apply(string $name, string $sql): void
	{
		// SQLite ignores foreign_keys inside a transaction.
		$sqlite = $this->db->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
		$foreignKeys = $sqlite && $this->db->value('PRAGMA foreign_keys')->int() === 1;

		if ($foreignKeys) {
			$this->db->run('PRAGMA foreign_keys = OFF');
		}

		try {
			$this->db->transaction(
				/** @throws \PDOException|\RuntimeException */
				function (Db $db) use ($sql, $name, $foreignKeys): void {
					$db->pdo->exec($sql);
					$broken = null;

					if ($foreignKeys) {
						$broken = $db->one('PRAGMA foreign_key_check');
					}

					if ($broken !== null) {
						$table = new Value($broken)->get('table')->string();
						$parent = new Value($broken)->get('parent')->string();

						throw new \RuntimeException(
							"{$name} leaves a row in {$table} that points at a missing row in {$parent}",
						);
					}

					$db->run('INSERT INTO migrations (name, ran_at) VALUES (?, ?)', [$name, gmdate('Y-m-d H:i:s')]);
				},
			);
		} finally {
			if ($foreignKeys) {
				$this->db->run('PRAGMA foreign_keys = ON');
			}
		}
	}
}
