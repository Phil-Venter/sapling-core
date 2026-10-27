<?php

use Sapling\App;
use Sapling\Config;

beforeEach(function () {
	$this->dir = tempDir();
	$this->app = App::boot($this->dir, new Config([]));
});

test('migrations:new creates an empty file named by time and its words', function () {
	[$code, $output] = runInApp($this->app, 'migrations:new', 'Add', 'notes-table!');
	$files = glob("{$this->dir}/database/migrations/*");

	expect($code)->toBe(0);
	expect($files)->toHaveCount(1);
	expect(basename($files[0]))->toMatch('/^\d{14}-add_notes_table\.sql$/');
	expect(file_get_contents($files[0]))->toBe('');
	expect($output)->toBe('Created database/migrations/' . basename($files[0]) . "\n");
});

test('migrations:new refuses a name with nothing usable in it', function () {
	[$code] = runInApp($this->app, 'migrations:new', '!!!');

	expect($code)->toBe(1);
	expect(is_dir("{$this->dir}/database/migrations"))->toBeFalse();
});
