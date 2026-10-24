<?php

use Sapling\Tests\Fixtures\Filter;
use Sapling\Tests\Fixtures\Priority;
use Sapling\Value;

test('string() casts numbers and Stringable objects', function (mixed $value, string $expected) {
	expect(new Value($value)->string('default'))->toBe($expected);
})->with([
	'a string' => ['hi', 'hi'],
	'an int' => [3, '3'],
	'missing' => [null, 'default'],
	'an array' => [['a'], 'default'],
	'true' => [true, 'default'],
	'a Stringable' => [
		new class {
			public function __toString(): string
			{
				return 'from an object';
			}
		},
		'from an object',
	],
	'another object' => [new ArrayIterator(), 'default'],
]);

test('int() takes whole numbers only', function (mixed $value, int $expected) {
	expect(new Value($value)->int(-1))->toBe($expected);
})->with([
	'a numeric string' => ['12', 12],
	'spaces around it' => [' 12 ', 12],
	'an int' => [7, 7],
	'trailing text' => ['12abc', -1],
	'a decimal' => ['1.5', -1],
	'missing' => [null, -1],
	'an array' => [['1'], -1],
	'true' => [true, -1],
]);

test('float() takes numbers', function (mixed $value, float $expected) {
	expect(new Value($value)->float(-1.0))->toBe($expected);
})->with([
	'a decimal string' => ['1.5', 1.5],
	'text' => ['abc', -1.0],
	'missing' => [null, -1.0],
	'an array' => [['1.5'], -1.0],
]);

test('bool() reads the usual words for true and false', function (mixed $value, bool $expected) {
	expect(new Value($value)->bool(true))->toBe($expected);
})->with([
	'on' => ['on', true],
	'off' => ['off', false],
	'a bool' => [false, false],
	'other text' => ['maybe', true],
	'missing' => [null, true],
	'an array' => [['1'], true],
]);

test('enum() finds the case by its value', function (mixed $value, BackedEnum $default, BackedEnum $expected) {
	expect(new Value($value)->enum($default))->toBe($expected);
})->with([
	'a string' => ['open', Filter::All, Filter::Open],
	'an empty string' => ['', Filter::Open, Filter::All],
	'missing, with a case for an empty string' => [null, Filter::Open, Filter::Open],
	'an array' => [['open'], Filter::All, Filter::All],
	'a numeric string' => ['1', Priority::Low, Priority::High],
	'no such case' => ['7', Priority::Low, Priority::Low],
	'text, with a case for 0' => ['abc', Priority::High, Priority::High],
	'missing, with a case for 0' => [null, Priority::High, Priority::High],
]);

test('rawArray() takes arrays only', function () {
	expect(new Value(['a', 'b'])->rawArray())->toBe(['a', 'b']);
	expect(new Value('a')->rawArray(['default']))->toBe(['default']);
	expect(new Value(null)->rawArray())->toBe([]);
});

test('items() wraps each item in a Value under its key', function () {
	$items = new Value(['a' => ['sku' => 'pen'], 'b' => ['sku' => 'ink']])->items();

	expect(array_keys($items))->toBe(['a', 'b']);
	expect($items['b']->get('sku')->string())->toBe('ink');
	expect(new Value('pen')->items())->toBe([]);
});

test('wrapping a Value twice changes nothing', function () {
	$twice = new Value(new Value('Ann'));

	expect($twice->raw)->toBe('Ann');
	expect($twice->string())->toBe('Ann');
});

test('get() reads keys at any depth', function () {
	$address = new Value(['city' => 'Cape Town', 'lines' => ['1 Main Road']]);

	expect($address->get()->rawArray())->toBe(['city' => 'Cape Town', 'lines' => ['1 Main Road']]);
	expect($address->get('city')->string())->toBe('Cape Town');
	expect($address->get('lines')->get(0)->string())->toBe('1 Main Road');
	expect($address->get('lines', 0)->string())->toBe('1 Main Road');
	expect($address->get('lines', 5, 'x')->raw)->toBeNull();
	expect($address->get('missing')->raw)->toBeNull();
	expect($address->get('city')->get('x')->raw)->toBeNull();
	expect(new Value(null)->get('city')->string('none'))->toBe('none');
});

test('get() reads public properties only', function () {
	$user = new class {
		public string $name = 'Ann';

		public object $address;

		private string $internal = 'not for you';

		public function __construct()
		{
			$this->address = (object) ['city' => 'Cape Town'];
		}
	};

	expect(new Value($user)->get('name')->string())->toBe('Ann');
	expect(
		new Value($user)
			->get('address')
			->get('city')
			->string(),
	)
		->toBe('Cape Town');
	expect(new Value($user)->get('internal')->raw)->toBeNull();
});

test('a backed enum reads as its value', function () {
	$high = new Value(Priority::High);

	expect($high->int())->toBe(1);
	expect($high->string())->toBe('1');
	expect($high->raw)->toBe(Priority::High);
});
