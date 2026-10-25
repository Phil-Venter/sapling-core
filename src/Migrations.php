<?php

namespace Sapling;

/**
 * The .sql files in a folder, run on a database in name order, each in a transaction, and recorded in its migrations
 * table.
 */
final readonly class Migrations
{
	private const string CREATE_TABLE = <<<SQL
			CREATE TABLE IF NOT EXISTS migrations (
				name VARCHAR(255) PRIMARY KEY,
				ran_at VARCHAR(19) NOT NULL
			)
		SQL;

	public function __construct(private Db $db, private string $dir) {}

	/**
	 * Runs the files that haven't run yet and returns their names. An empty file is skipped and not recorded.
	 *
	 * @return list<string>
	 * @throws \RuntimeException When a file fails. The message names the file, which is rolled back, and the files before it
	 *     stay recorded.
	 * @throws \Throwable
	 */
	public function run(): array
	{
		$ran = [];

		foreach ($this->all() as $name => $ranAt) {
			if ($ranAt !== null) {
				continue;
			}

			$sql = (string) file_get_contents("{$this->dir}/{$name}");

			if (trim($sql) === '') {
				continue;
			}

			$this->apply($name, $sql);
			$ran[] = $name;
		}

		return $ran;
	}

	/**
	 * Every file in name order, with the UTC time it ran, or null while it's pending.
	 *
	 * @return array<string, ?string>
	 * @throws \PDOException When the migrations table can't be created or read.
	 */
	public function all(): array
	{
		$this->db->run(self::CREATE_TABLE);
		$files = glob("{$this->dir}/*.sql");
		$sql = <<<SQL
			SELECT name, ran_at
			FROM migrations
			SQL;
		$rows = $this->db->all($sql);
		$ran = new Value(array_column($rows, 'ran_at', 'name'));
		$all = [];

		if ($files === false) {
			$files = [];
		}

		foreach ($files as $file) {
			$name = basename($file);
			$ranAt = $ran->get($name)->string();
			$all[$name] = null;

			if ($ranAt !== '') {
				$all[$name] = $ranAt;
			}
		}

		return $all;
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
				function () use ($name, $sql, $foreignKeys): void {
					$this->migrate($name, $sql, $foreignKeys);
				},
			);
		} catch (\PDOException $e) {
			throw new \RuntimeException("{$name} failed: {$e->getMessage()}", 0, $e);
		} finally {
			if ($foreignKeys) {
				$this->db->run('PRAGMA foreign_keys = ON');
			}
		}
	}

	/** @throws \PDOException|\RuntimeException */
	private function migrate(string $name, string $migration, bool $foreignKeys): void
	{
		$this->db->pdo->exec($migration);
		$broken = null;

		if ($foreignKeys) {
			$broken = $this->db->one('PRAGMA foreign_key_check');
		}

		if ($broken !== null) {
			$check = new Value($broken);
			$table = $check->get('table')->string();
			$parent = $check->get('parent')->string();

			throw new \RuntimeException("{$name} leaves a row in {$table} that points at a missing row in {$parent}");
		}

		$sql = <<<SQL
			INSERT INTO migrations (name, ran_at)
			VALUES (?, ?)
			SQL;
		$this->db->run($sql, [$name, gmdate('Y-m-d H:i:s')]);
	}
}
