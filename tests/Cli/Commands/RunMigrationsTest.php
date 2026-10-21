<?php

use Sapling\App;
use Sapling\Cli\Commands\RunMigrations;
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

test('migrations:run prints each file it ran', function () {
	($this->migration)('001_notes.sql', 'CREATE TABLE notes (id INTEGER PRIMARY KEY);');
	($this->migration)('002_tags.sql', 'CREATE TABLE tags (id INTEGER PRIMARY KEY);');

	expect(runInApp($this->app, 'migrations:run'))->toBe([0, "Migrated 001_notes.sql\nMigrated 002_tags.sql\n"]);
});

test('migrations:run names an empty file it skipped', function () {
	($this->migration)('001_notes.sql', "\n");

	expect(runInApp($this->app, 'migrations:run'))->toBe([
		0,
		"Skipped 001_notes.sql, it's empty\nNothing to migrate.\n",
	]);
});

test('migrations:run exits with 1 when a migration fails', function () {
	($this->migration)('001_broken.sql', 'THIS IS NOT SQL;');

	[$code, $output] = runInApp($this->app, 'migrations:run');

	expect($code)->toBe(1);
	expect($output)->toBe('');
});
