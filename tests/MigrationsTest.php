<?php

use Sapling\Db;
use Sapling\Migrations;

beforeEach(function () {
	$this->dir = sys_get_temp_dir() . '/sapling-migrations-' . bin2hex(random_bytes(4));
	mkdir("{$this->dir}/migrations", recursive: true);
	$this->db = Db::sqlite("{$this->dir}/test.db");
	$this->migrations = new Migrations($this->db, "{$this->dir}/migrations");
	$this->migration = fn(string $name, string $sql) => file_put_contents("{$this->dir}/migrations/{$name}", $sql);
});

afterEach(function () {
	array_map('unlink', [...glob("{$this->dir}/migrations/*"), ...glob("{$this->dir}/test.db*")]);
	rmdir("{$this->dir}/migrations");
	rmdir($this->dir);
});

test('run() runs new files in name order, once each', function () {
	($this->migration)(
		'002_tags.sql',
		'CREATE TABLE tags (id INTEGER PRIMARY KEY, note_id INTEGER NOT NULL REFERENCES notes (id));',
	);
	($this->migration)(
		'001_notes.sql',
		'CREATE TABLE notes (id INTEGER PRIMARY KEY); CREATE INDEX notes_id ON notes (id);',
	);

	expect($this->migrations->run())->toBe(['001_notes.sql', '002_tags.sql']);
	expect($this->migrations->run())->toBe([]);
});

test('all() gives each file the time it ran, or null while pending', function () {
	($this->migration)('001_notes.sql', 'CREATE TABLE notes (id INTEGER PRIMARY KEY);');
	$this->migrations->run();
	($this->migration)('002_tags.sql', 'CREATE TABLE tags (id INTEGER PRIMARY KEY);');

	$all = $this->migrations->all();

	expect(array_keys($all))->toBe(['001_notes.sql', '002_tags.sql']);
	expect($all['001_notes.sql'])->toMatch('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/');
	expect($all['002_tags.sql'])->toBeNull();
});

test('an empty file is not recorded', function () {
	($this->migration)('001_notes.sql', "\n");

	$empty = $this->migrations->run();
	($this->migration)('001_notes.sql', 'CREATE TABLE notes (id INTEGER PRIMARY KEY);');
	$filled = $this->migrations->run();

	expect($empty)->toBe([]);
	expect($filled)->toBe(['001_notes.sql']);
});

test('a failing file rolls back without undoing earlier ones', function () {
	($this->migration)('001_notes.sql', 'CREATE TABLE notes (id INTEGER PRIMARY KEY);');
	($this->migration)('002_broken.sql', 'CREATE TABLE half (id INTEGER); THIS IS NOT SQL;');

	expect(fn() => $this->migrations->run())->toThrow(RuntimeException::class, '002_broken.sql failed: ');
	expect(array_column($this->db->all('SELECT name FROM migrations'), 'name'))->toBe(['001_notes.sql']);
	expect($this->db->value("SELECT count(*) FROM sqlite_master WHERE name = 'half'")->int())->toBe(0);
	expect($this->db->value("SELECT count(*) FROM sqlite_master WHERE name = 'notes'")->int())->toBe(1);
});

test('rebuilding a table keeps the rows that point at it', function () {
	($this->migration)('001_users.sql', <<<SQL
		CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT);
		CREATE TABLE notes (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE);
		INSERT INTO users (id, name) VALUES (1, 'Ann');
		INSERT INTO notes (user_id) VALUES (1), (1), (1);
		SQL);
	($this->migration)('002_rebuild_users.sql', <<<SQL
		CREATE TABLE users_new (id INTEGER PRIMARY KEY, name TEXT NOT NULL DEFAULT '');
		INSERT INTO users_new (id, name) SELECT id, name FROM users;
		DROP TABLE users;
		ALTER TABLE users_new RENAME TO users;
		SQL);

	$this->migrations->run();

	expect($this->db->value('SELECT count(*) FROM notes')->int())->toBe(3);
	expect($this->db->value('PRAGMA foreign_keys')->int())->toBe(1);
});

test('a migration that leaves an orphaned row rolls back', function () {
	($this->migration)('001_users.sql', <<<SQL
		CREATE TABLE users (id INTEGER PRIMARY KEY);
		CREATE TABLE notes (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE);
		INSERT INTO users (id) VALUES (1);
		INSERT INTO notes (user_id) VALUES (1);
		SQL);
	($this->migration)('002_drop_users.sql', 'DELETE FROM users;');

	expect(fn() => $this->migrations->run())
		->toThrow(
			RuntimeException::class,
			'002_drop_users.sql leaves a row in notes that points at a missing row in users',
		);
	expect($this->db->value('SELECT count(*) FROM users')->int())->toBe(1);
	expect(array_column($this->db->all('SELECT name FROM migrations'), 'name'))->toBe(['001_users.sql']);
	expect($this->db->value('PRAGMA foreign_keys')->int())->toBe(1);
});
