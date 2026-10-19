<?php

use Sapling\Cli\Console;
use Sapling\Cli\UsageException;

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

	expect(fn() => $console->option('days'))->toThrow(UsageException::class, '--days needs a value');
	expect(fn() => $console->flag('force'))->toThrow(UsageException::class, '--force is a flag');
});

test('every word after -- is an argument, even one that starts with dashes', function () {
	$console = new Console(['bin/console', 'notes:add', 'first', '--', '--not-a-flag', '--x=1']);

	expect($console->arg(0)->string())->toBe('first');
	expect($console->arg(1)->string())->toBe('--not-a-flag');
	expect($console->arg(2)->string())->toBe('--x=1');
	expect($console->flag('not-a-flag'))->toBeFalse();
});

test('ask() prints the question and reads the trimmed answer, and a blank answer or no input is empty', function (
	string $input,
	string $output,
) {
	expect(runConsole(['hello'], input: $input)['stdout'])->toBe($output);
})->with([
	'an answer' => ["  Ann \n", "Name? Hello Ann.\n"],
	'a blank answer' => ["\n", "Name? Hello nobody.\n"],
	'the end of input, which ends the line' => ['', "Name? \nHello nobody.\n"],
]);
