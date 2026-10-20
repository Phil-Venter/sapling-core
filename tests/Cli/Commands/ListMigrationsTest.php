<?php

use Sapling\App;
use Sapling\Cli\Commands\ListMigrations;
use Sapling\Cli\Commands\RunMigrations;
use Sapling\Db;

beforeEach(function () {
	$this->dir = sys_get_temp_dir() . '/sapling-migrations-' . bin2hex(random_bytes(4));
	mkdir("{$this->dir}/database/migrations", recursive: true);
	$this->app = new App($this->dir);
	$this->app->container->set(Db::class, fn() => Db::sqlite("{$this->dir}/test.db"));
	$this->app->commands->add('migrations:run', RunMigrations::class);
	$this->app->commands->add('migrations:list', ListMigrations::class);
});

afterEach(function () {
	array_map('unlink', [...glob("{$this->dir}/database/migrations/*"), ...glob("{$this->dir}/test.db*")]);
	rmdir("{$this->dir}/database/migrations");
	rmdir("{$this->dir}/database");
	rmdir($this->dir);
});

test('migrations:list shows each file as ran, pending or empty, in name order', function () {
	file_put_contents("{$this->dir}/database/migrations/001_notes.sql", 'CREATE TABLE notes (id INTEGER PRIMARY KEY);');
	runInApp($this->app, 'migrations:run');
	file_put_contents("{$this->dir}/database/migrations/002_tags.sql", 'CREATE TABLE tags (id INTEGER PRIMARY KEY);');
	file_put_contents("{$this->dir}/database/migrations/003_later.sql", '');

	[$code, $output] = runInApp($this->app, 'migrations:list');

	expect($code)->toBe(0);
	expect($output)->toMatch('/^001_notes\.sql  ran \d{4}-\d\d-\d\d \d\d:\d\d:\d\d UTC\n002_tags\.sql   pending\n003_later\.sql  empty, so migrations:run skips it\n$/');
});

test('migrations:list says so when there are no migrations', function () {
	expect(runInApp($this->app, 'migrations:list'))->toBe([0, "No migrations in database/migrations.\n"]);
});
