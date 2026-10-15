<?php

use Sapling\Value;

test('string() reads strings, numbers and Stringable objects, and gives the default for the rest', function (
	mixed $value,
	string $expected,
) {
	expect(new Value($value)->string('default'))->toBe($expected);
})->with([
	'a string' => ['hi', 'hi'],
	'an empty string' => ['', ''],
	'an int' => [3, '3'],
	'a float' => [1.5, '1.5'],
	'missing' => [null, 'default'],
	'an array' => [['a'], 'default'],
	'true' => [true, 'default'],
	'false' => [false, 'default'],
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
	'with spaces' => [' 12 ', 12],
	'negative' => ['-3', -3],
	'an int' => [7, 7],
	'trailing text' => ['12abc', -1],
	'a fraction' => ['1.5', -1],
	'empty' => ['', -1],
	'missing' => [null, -1],
	'an array' => [['1'], -1],
	'true' => [true, -1],
]);

test('float() takes numbers', function (mixed $value, float $expected) {
	expect(new Value($value)->float(-1.0))->toBe($expected);
})->with([
	'a decimal string' => ['1.5', 1.5],
	'a whole number' => ['2', 2.0],
	'a float' => [0.25, 0.25],
	'text' => ['abc', -1.0],
	'missing' => [null, -1.0],
	'an array' => [['1.5'], -1.0],
]);

test('bool() reads the usual words for true and false', function (mixed $value, bool $expected) {
	expect(new Value($value)->bool(true))->toBe($expected);
})->with([
	'1' => ['1', true],
	'on' => ['on', true],
	'yes' => ['yes', true],
	'true' => ['true', true],
	'0' => ['0', false],
	'off' => ['off', false],
	'false' => ['false', false],
	'empty' => ['', false],
	'a bool' => [false, false],
	'other text' => ['maybe', true],
	'missing' => [null, true],
	'an array' => [['1'], true],
]);

test('array() takes arrays only, and $raw holds the value as it arrived', function () {
	expect(new Value(['a', 'b'])->array())->toBe(['a', 'b']);
	expect(new Value('a')->array(['default']))->toBe(['default']);
	expect(new Value(null)->array())->toBe([]);
	expect(new Value(['a'])->raw)->toBe(['a']);
	expect(new Value(null)->raw)->toBeNull();
});

test('get() reads a key inside an array, and anything else gives an empty Value', function () {
	$address = new Value(['city' => 'Cape Town', 'lines' => ['1 Main Road']]);

	expect($address->get('city')->string())->toBe('Cape Town');
	expect($address->get('lines')->get(0)->string())->toBe('1 Main Road');
	expect($address->get('lines', 0)->string())->toBe('1 Main Road');
	expect($address->get('lines', 5, 'x')->raw)->toBeNull();
	expect($address->get('missing')->raw)->toBeNull();
	expect($address->get('city')->get('x')->raw)->toBeNull();
	expect(new Value(null)->get('city')->string('none'))->toBe('none');
});

test('get() reads an object\'s public properties, never its private ones', function () {
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
	$high = new Value(Sapling\Tests\Fixtures\Priority::High);

	expect($high->int())->toBe(1);
	expect($high->string())->toBe('1');
	expect($high->raw)->toBe(Sapling\Tests\Fixtures\Priority::High);
});
