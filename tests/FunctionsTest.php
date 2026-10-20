<?php

use Sapling\Http\HttpException;

test('e()', function () {
	expect(e('<a href="x">O\'Reilly & Co</a>'))->toBe('&lt;a href=&quot;x&quot;&gt;O&#039;Reilly &amp; Co&lt;/a&gt;');
});

test('abort() and abort_if() throw HttpException', function () {
	expect(fn() => abort(404, 'gone', [
		'X-Why' => 'deleted',
	]))->toThrow(fn(HttpException $e) => expect([$e->status, $e->headers])->toBe([
		404,
		['X-Why' => 'deleted'],
	]));
	expect(fn() => abort_if(true, 403))->toThrow(HttpException::class, 'Forbidden');
	expect(abort_if(false, 403))->toBeNull();
});

test('attr() and attrs() escape values and leave out empty ones', function () {
	expect(attr('title', '"><script>'))->toBe('title="&quot;&gt;&lt;script&gt;"');
	expect(attr('class', ['col', '0', '', null]))->toBe('class="col 0"');
	expect(attr('tabindex', 0))->toBe('tabindex="0"');
	expect(attr('required', true))->toBe('required');
	expect([attr('hidden', false), attr('id', null), attr('id', '')])->toBe([null, null, null]);
	expect(attrs(['class' => 'note', 'hidden' => false, 'data-id' => 7, 'open' => true]))
		->toBe('class="note" data-id="7" open');
});

test('blank()', function (mixed $value, bool $blank) {
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
	expect(fn() => blank(new Sapling\Value('')))->toThrow(LogicException::class, 'not a Value');
});

test('datetime()', function (
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

test('datetime() refuses a blank string', function (string $blank) {
	expect(fn() => datetime($blank))->toThrow(DateMalformedStringException::class, 'A blank string is not a date');
})->with(['empty' => '', 'spaces' => '  ']);

test('datetime() without a timezone uses the default', function () {
	$default = date_default_timezone_get();
	date_default_timezone_set('Africa/Johannesburg');

	try {
		expect(datetime(0)->format('c'))->toBe('1970-01-01T02:00:00+02:00');
	} finally {
		date_default_timezone_set($default);
	}
});
test('uuid() makes a version 7 UUID, ordered by time', function () {
	$first = uuid();
	usleep(2000);
	$second = uuid();
	$many = array_map(fn() => uuid(), range(1, 1000));

	expect($first)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
	expect(strcmp($first, $second))->toBeLessThan(0);
	expect(array_unique($many))->toHaveCount(1000);
});
