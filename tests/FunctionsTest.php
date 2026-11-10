<?php

use Sapling\Http\HttpException;
use Sapling\Tests\Fixtures\Priority;
use Sapling\Value;

test('e() escapes HTML, quotes included', function () {
	expect(e('<a href="x">O\'Reilly & Co</a>'))->toBe('&lt;a href=&quot;x&quot;&gt;O&#039;Reilly &amp; Co&lt;/a&gt;');
});

test('e() prints a backed enum as its value', function () {
	expect(e(Priority::High))->toBe('1');
});

test('abort() throws an HttpException with its status and headers', function () {
	$abort = fn() => abort(404, 'gone', ['X-Why' => 'deleted']);

	expect($abort)->toThrow(function (HttpException $e) {
		expect($e->status)->toBe(404);
		expect($e->headers)->toBe(['X-Why' => 'deleted']);
	});
});

test('attrs() writes an escaped attribute, or nothing for an empty value', function (
	string $key,
	mixed $value,
	string $expected,
) {
	expect(attrs([$key => $value]))->toBe($expected);
})->with([
	'a value to escape' => ['title', '"><script>', 'title="&quot;&gt;&lt;script&gt;"'],
	'a list, without its empty items' => ['class', ['col', '0', '', null], 'class="col 0"'],
	'a list of only empty items' => ['class', ['', null], ''],
	'0' => ['tabindex', 0, 'tabindex="0"'],
	'true, as a bare attribute' => ['required', true, 'required'],
	'false' => ['hidden', false, ''],
	'null' => ['id', null, ''],
	'an empty string' => ['id', '', ''],
]);

test('attrs() joins the attributes, leaving out empty ones', function () {
	expect(attrs(['class' => 'note', 'hidden' => false, 'data-id' => 7, 'open' => true]))
		->toBe('class="note" data-id="7" open');
});

test('attrs() refuses a name that would write a second attribute', function (string $name) {
	expect(fn() => attrs([$name => 'x']))->toThrow(InvalidArgumentException::class, "isn't an attribute name");
})->with([
	'a space' => 'title onclick',
	'an equals sign' => 'title=',
	'a slash' => 'title/onclick',
	'a line break' => "title\n",
	'empty' => '',
]);

test('blank() tells empty values from falsy ones', function (mixed $value, bool $blank) {
	expect(blank($value))->toBe($blank);
})->with([
	'null' => [null, true],
	'whitespace' => [" \n", true],
	'empty array' => [[], true],
	'empty Countable' => [new ArrayObject(), true],
	'"0"' => ['0', false],
	'0' => [0, false],
	'false' => [false, false],
	'an object' => [new stdClass(), false],
]);

test('blank() refuses a Value', function () {
	expect(fn() => blank(new Value('')))->toThrow(LogicException::class, 'was given a Value');
});

test('datetime() returns the date in the timezone asked for', function (
	DateTimeInterface|int|float|string|null $value,
	DateTimeZone|string|null $timezone,
	string $expected,
) {
	expect(datetime($value, $timezone)->format('Y-m-d H:i:s.u P'))->toBe($expected);
})->with([
	'in another zone' => [0, 'Africa/Johannesburg', '1970-01-01 02:00:00.000000 +02:00'],
	'a date string, read in the zone' => [
		'2026-10-07 07:31:34',
		'Africa/Johannesburg',
		'2026-10-07 07:31:34.000000 +02:00',
	],
	'a DateTimeZone' => [0, new DateTimeZone('Africa/Johannesburg'), '1970-01-01 02:00:00.000000 +02:00'],
	'another date' => [
		new DateTime('2026-10-07 09:31:34', new DateTimeZone('Africa/Johannesburg')),
		'UTC',
		'2026-10-07 07:31:34.000000 +00:00',
	],
]);

test('datetime() refuses a string that is not a date', function (string $junk) {
	expect(fn() => datetime($junk))->toThrow(DateMalformedStringException::class);
})->with([
	'empty' => '',
	'spaces' => '  ',
	'a letter, which PHP reads as a timezone' => 'x',
	'only a timezone' => 'Africa/Johannesburg',
	'a day the month doesn\'t have' => '2026-02-30',
	'a zero date' => '0000-00-00 00:00:00',
	'words' => 'next thursdayish',
]);

test('datetime() reads a date string in any form PHP knows', function (string $date, string $expected) {
	expect(datetime($date, 'UTC')->format('Y-m-d H:i'))->toBe($expected);
})->with([
	'a leap day' => ['2024-02-29', '2024-02-29 00:00'],
	'a date with its own zone' => ['15 Oct 2026 09:00 Africa/Johannesburg', '2026-10-15 07:00'],
	'a month and a day' => ['15 October UTC', date('Y') . '-10-15 00:00'],
	'a relative date' => ['first day of january 2026', '2026-01-01 00:00'],
]);

test('datetime() reads now as the current time', function () {
	expect(abs(datetime('now')->getTimestamp() - time()))->toBeLessThan(2);
});

test('datetime() without a timezone uses the default', function () {
	$default = date_default_timezone_get();
	date_default_timezone_set('Africa/Johannesburg');

	try {
		expect(datetime(0)->format('c'))->toBe('1970-01-01T02:00:00+02:00');
	} finally {
		date_default_timezone_set($default);
	}
});
