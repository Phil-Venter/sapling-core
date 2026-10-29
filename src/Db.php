<?php

namespace Sapling;

/**
 * Wraps PDO to run SQL with ? placeholders.
 */
final readonly class Db
{
	/**
	 * Sets the connection to throw on errors, fetch rows as arrays and use real prepared statements.
	 *
	 * @throws \PDOException
	 */
	public function __construct(public \PDO $pdo)
	{
		$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
		$pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
	}

	/**
	 * Opens a SQLite file with WAL, synchronous NORMAL, foreign keys, a 5 second busy timeout and a 64 MB journal size limit.
	 *
	 * @throws \RuntimeException When the file can't be opened.
	 */
	public static function sqlite(string $file): self
	{
		try {
			$db = new self(new \PDO("sqlite:{$file}"));
			$db->pdo->exec('PRAGMA busy_timeout = 5000');
			$db->pdo->exec('PRAGMA journal_mode = WAL');
			$db->pdo->exec('PRAGMA synchronous = NORMAL');
			$db->pdo->exec('PRAGMA journal_size_limit = 67108864');
			$db->pdo->exec('PRAGMA foreign_keys = ON');
		} catch (\PDOException $e) {
			throw new \RuntimeException("Cannot open {$file}: {$e->getMessage()}", 0, $e);
		}

		return $db;
	}

	/**
	 * @param array<array-key, mixed> $params
	 * @return list<array<string, mixed>>
	 * @throws \PDOException When the query fails.
	 */
	public function all(string $sql, array $params = []): array
	{
		return $this->query($sql, $params)->fetchAll();
	}

	/**
	 * @param array<array-key, mixed> $params
	 * @return array<string, mixed>|null
	 * @throws \PDOException When the query fails.
	 */
	public function one(string $sql, array $params = []): ?array
	{
		$row = $this->query($sql, $params)->fetch();
		if ($row === false) {
			return null;
		}

		return $row;
	}

	/**
	 * The first column of the first row. No row gives an empty Value.
	 *
	 * @param array<array-key, mixed> $params
	 * @throws \PDOException When the query fails.
	 */
	public function value(string $sql, array $params = []): Value
	{
		// Not fetchColumn(), which also returns false for a real false, such as a PostgreSQL boolean.
		$row = $this->query($sql, $params)->fetch(\PDO::FETCH_NUM);
		if (!is_array($row)) {
			return new Value(null);
		}

		return new Value($row[0] ?? null);
	}

	/**
	 * Returns the number of rows changed.
	 *
	 * @param array<array-key, mixed> $params
	 * @throws \PDOException When the statement fails.
	 */
	public function run(string $sql, array $params = []): int
	{
		return $this->query($sql, $params)->rowCount();
	}

	/** @throws \PDOException */
	public function lastId(): string
	{
		return (string) $this->pdo->lastInsertId();
	}

	/**
	 * @template T
	 * @param \Closure(self): T $fn
	 * @return T
	 * @throws \PDOException When the transaction can't start or commit.
	 * @throws \Throwable Anything the closure throws, after rolling back.
	 */
	public function transaction(\Closure $fn): mixed
	{
		match ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
			'sqlite' => $this->pdo->exec('BEGIN IMMEDIATE'),
			default => $this->pdo->beginTransaction(),
		};

		try {
			$result = $fn($this);
			if ($this->pdo->inTransaction()) {
				$this->pdo->commit();
			}
		} catch (\Throwable $e) {
			if ($this->pdo->inTransaction()) {
				$this->pdo->rollBack();
			}

			throw $e;
		}

		return $result;
	}

	/**
	 * @param array<array-key, mixed> $params
	 * @throws \PDOException
	 */
	private function query(string $sql, array $params): \PDOStatement
	{
		$statement = $this->pdo->prepare($sql);
		$statement->execute($params);
		return $statement;
	}
}
