<?php

use Sapling\Cli\Console;

test('parses the command, options, flags and args', function () {
	$console = new Console(['bin/console', 'notes:prune', '--days=7', '--dry-run', 'x']);

	expect($console->command)->toBe('notes:prune');
	expect($console->option('days')->int())->toBe(7);
	expect($console->flag('dry-run'))->toBeTrue();
	expect($console->arg(0)->string())->toBe('x');
});

test('a missing option or argument gives the default', function () {
	$console = new Console(['bin/console', 'notes:prune', '--days=soon']);

	expect($console->option('days')->int(30))->toBe(30);
	expect($console->option('missing')->string('none'))->toBe('none');
	expect($console->arg(0)->raw)->toBeNull();
});

test('an option without =value, or a flag with one, throws rather than giving the default', function () {
	$console = new Console(['bin/console', 'notes:prune', '--days', '7', '--force=yes']);

	expect(fn() => $console->option('days'))->toThrow(InvalidArgumentException::class, '--days needs a value');
	expect(fn() => $console->flag('force'))->toThrow(InvalidArgumentException::class, '--force is a flag');
});
