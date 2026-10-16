<?php

use Sapling\App;
use Sapling\Cli\Commands\RunMigrations;
use Sapling\Cli\Console;
use Sapling\Db;

beforeEach(function () {
	$this->dir = sys_get_temp_dir() . '/sapling-migrate-' . bin2hex(random_bytes(4));
	mkdir("{$this->dir}/database/migrations", recursive: true);
	$this->app = new App($this->dir);
	$this->app->container->set(Db::class, fn() => Db::sqlite("{$this->dir}/test.db"));
	$this->app->commands->add('migrations:run', RunMigrations::class);
	$this->migration = fn(string $name, string $sql) => file_put_contents(
		"{$this->dir}/database/migrations/{$name}",
		$sql,
	);
});

afterEach(function () {
	array_map('unlink', [...glob("{$this->dir}/database/migrations/*"), ...glob("{$this->dir}/test.db*")]);
	rmdir("{$this->dir}/database/migrations");
	rmdir("{$this->dir}/database");
	rmdir($this->dir);
});

test('migrate runs new files in name order, once each', function () {
	($this->migration)(
		'002_tags.sql',
		'CREATE TABLE tags (id INTEGER PRIMARY KEY, note_id INTEGER NOT NULL REFERENCES notes (id));',
	);
	($this->migration)(
		'001_notes.sql',
		'CREATE TABLE notes (id INTEGER PRIMARY KEY); CREATE INDEX notes_id ON notes (id);',
	);

	$first = runInApp($this->app, 'migrations:run');
	$second = runInApp($this->app, 'migrations:run');

	expect($first)->toBe([0, "Migrated 001_notes.sql\nMigrated 002_tags.sql\nRan 2 migrations.\n"]);
	expect($second)->toBe([0, "Nothing to migrate.\n"]);
	$names = $this->app->container->get(Db::class)->all('SELECT name FROM migrations ORDER BY name');

	expect(array_column($names, 'name'))->toBe(['001_notes.sql', '002_tags.sql']);
});

test('an empty file is skipped, not recorded, so it runs once it has SQL in it', function () {
	($this->migration)('001_notes.sql', "\n");

	$empty = runInApp($this->app, 'migrations:run');
	($this->migration)('001_notes.sql', 'CREATE TABLE notes (id INTEGER PRIMARY KEY);');
	$filled = runInApp($this->app, 'migrations:run');

	expect($empty)->toBe([0, "Skipped 001_notes.sql, it's empty\nNothing to migrate.\n"]);
	expect($filled)->toBe([0, "Migrated 001_notes.sql\nRan 1 migration.\n"]);
});

test('a failing file rolls back with its migrations row, and earlier files stay', function () {
	($this->migration)('001_notes.sql', 'CREATE TABLE notes (id INTEGER PRIMARY KEY);');
	($this->migration)('002_broken.sql', 'CREATE TABLE half (id INTEGER); THIS IS NOT SQL;');
	$db = $this->app->container->get(Db::class);
	$migrate = $this->app->container->get(RunMigrations::class);

	ob_start();

	try {
		expect(fn() => $migrate(new Console(['bin/console', 'migrations:run'])))->toThrow(PDOException::class);
	} finally {
		ob_end_clean();
	}

	expect(array_column($db->all('SELECT name FROM migrations'), 'name'))->toBe(['001_notes.sql']);
	expect($db->value("SELECT count(*) FROM sqlite_master WHERE name = 'half'")->int())->toBe(0);
	expect($db->value("SELECT count(*) FROM sqlite_master WHERE name = 'notes'")->int())->toBe(1);
});
