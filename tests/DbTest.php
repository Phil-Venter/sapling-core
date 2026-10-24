<?php

use Sapling\Db;

beforeEach(function () {
	$this->dir = sys_get_temp_dir() . '/sapling-db-' . bin2hex(random_bytes(4));
	mkdir($this->dir);
	$this->db = Db::sqlite("{$this->dir}/test.db");
	$this->db->run('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT NOT NULL)');
});

afterEach(function () {
	unset($this->db);
	array_map('unlink', glob("{$this->dir}/*"));
	rmdir($this->dir);
});

test('sqlite() sets its pragmas', function () {
	expect($this->db->value('PRAGMA journal_mode')->string())->toBe('wal');
	expect($this->db->value('PRAGMA synchronous')->int())->toBe(1);
	expect($this->db->value('PRAGMA foreign_keys')->int())->toBe(1);
	expect($this->db->value('PRAGMA busy_timeout')->int())->toBe(5000);
	expect($this->db->value('PRAGMA journal_size_limit')->int())->toBe(67_108_864);
});

test('transaction() on SQLite takes the write lock up front', function () {
	$this->db->run('INSERT INTO notes (body) VALUES (?)', ['first']);
	$other = Db::sqlite("{$this->dir}/test.db");
	$other->pdo->exec('PRAGMA busy_timeout = 0');
	$refused = '';

	$this->db->transaction(function (Db $db) use ($other, &$refused) {
		$db->value('SELECT body FROM notes');

		try {
			$other->run('UPDATE notes SET body = ?', ['other']);
		} catch (PDOException $e) {
			$refused = $e->getMessage();
		}

		$db->run('UPDATE notes SET body = ?', ['mine']);
	});

	expect($refused)->toContain('database is locked');
	expect($other->value('SELECT body FROM notes')->string())->toBe('mine');
});

test('all() and one() read rows as arrays', function () {
	$this->db->run("INSERT INTO notes (body) VALUES ('a'), ('b')");

	expect($this->db->all('SELECT id, body FROM notes ORDER BY id'))->toBe([
		['id' => 1, 'body' => 'a'],
		['id' => 2, 'body' => 'b'],
	]);
	expect($this->db->one('SELECT body FROM notes WHERE id = ?', [2]))->toBe(['body' => 'b']);
});

test('transaction() commits and returns the closure\'s result', function (bool $committed) {
	$id = $this->db->transaction(function (Db $db) use ($committed) {
		$db->run("INSERT INTO notes (body) VALUES ('kept')");

		// MySQL commits by itself after a statement such as CREATE TABLE.
		if ($committed) {
			$db->pdo->commit();
		}

		return $db->lastId();
	});

	expect($id)->toBe('1');
	expect($this->db->value('SELECT body FROM notes')->string())->toBe('kept');
	expect($this->db->pdo->inTransaction())->toBeFalse();
})->with(['a plain transaction' => false, 'a statement that committed by itself' => true]);

test('transaction() rolls back and rethrows', function () {
	$failing = fn() => $this->db->transaction(function (Db $db) {
		$db->run("INSERT INTO notes (body) VALUES ('lost')");

		throw new RuntimeException('stop');
	});

	expect($failing)->toThrow(RuntimeException::class, 'stop');
	expect($this->db->value('SELECT count(*) FROM notes')->int())->toBe(0);
	expect($this->db->pdo->inTransaction())->toBeFalse();
});
