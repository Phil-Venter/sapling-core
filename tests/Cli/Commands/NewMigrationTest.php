<?php

use Sapling\App;
use Sapling\Config;

beforeEach(function () {
	$this->dir = sys_get_temp_dir() . '/sapling-new-migration-' . bin2hex(random_bytes(4));
	mkdir($this->dir);
	$this->app = App::boot($this->dir, new Config([]));
});

afterEach(function () {
	$files = glob("{$this->dir}/database/migrations/*");

	if ($files !== false) {
		array_map('unlink', $files);
	}

	foreach (['/database/migrations', '/database', ''] as $folder) {
		if (!is_dir($this->dir . $folder)) {
			continue;
		}

		rmdir($this->dir . $folder);
	}
});

test('migrations:new creates the folder and an empty file named by UTC time and its words in snake case', function () {
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
