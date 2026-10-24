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

test('a missing or unreadable option or argument gives the default', function () {
	$console = new Console(['bin/console', 'notes:prune', '--days=soon']);

	expect($console->option('days')->int(30))->toBe(30);
	expect($console->option('missing')->string('none'))->toBe('none');
	expect($console->arg(0)->raw)->toBeNull();
});

test('an option without =value, or a flag with one, throws', function () {
	$console = new Console(['bin/console', 'notes:prune', '--days', '7', '--force=yes']);

	expect(fn() => $console->option('days'))->toThrow(UsageException::class, '--days needs a value');
	expect(fn() => $console->flag('force'))->toThrow(UsageException::class, '--force is a flag');
});

test('every word after -- is an argument', function () {
	$console = new Console(['bin/console', 'notes:add', 'first', '--', '--not-a-flag', '--x=1']);

	expect($console->args)->toBe(['first', '--not-a-flag', '--x=1']);
	expect($console->arg(1)->string())->toBe('--not-a-flag');
	expect($console->flag('not-a-flag'))->toBeFalse();
});

test('ask() prints the question and reads the trimmed answer', function (
	string $input,
	?string $answer,
	string $printed,
) {
	$answers = fopen('php://memory', 'r+');
	fwrite($answers, $input);
	rewind($answers);
	$console = new Console(['bin/console', 'notes:prune'], $answers);

	ob_start();
	$value = $console->ask('Delete them?');

	expect(ob_get_clean())->toBe($printed);
	expect($value->raw)->toBe($answer);
})->with([
	'an answer' => ["  yes \n", 'yes', 'Delete them? '],
	'a blank answer' => ["\n", null, 'Delete them? '],
	'the end of input, which ends the line' => ['', null, "Delete them? \n"],
]);

test('table() lines up columns by characters', function () {
	$console = new Console(['bin/console', 'cafes:list']);

	ob_start();
	$console->table([['Café', 'open'], ['Bar', 'closed'], [], ['Diner', 'open']]);

	expect(ob_get_clean())->toBe("Café   open\nBar    closed\n\nDiner  open\n");
});
