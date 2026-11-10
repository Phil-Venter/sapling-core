<?php

if (!function_exists('abort')) {
	/**
	 * Stops the request with an HTTP error, as in abort(404).
	 *
	 * @param array<string, string> $headers
	 * @throws Sapling\Http\HttpException Always.
	 */
	function abort(int $status, string $message = '', array $headers = [], ?Throwable $previous = null): never
	{
		throw new Sapling\Http\HttpException($status, $message, $headers, $previous);
	}
}

if (!function_exists('attrs')) {
	/**
	 * Escaped HTML attributes, such as attrs(['class' => ['note', 'pinned']]) for class="note pinned". A value of true gives a bare attribute, and null, false or an empty value leaves the attribute out. A name with a space, a slash or an equals sign in it throws an InvalidArgumentException.
	 *
	 * @param array<string, mixed> $attrs
	 */
	function attrs(array $attrs): string
	{
		$parts = [];
		foreach ($attrs as $key => $value) {
			// Escaping can't stop a name such as "x onclick" from writing a second attribute.
			if ($key === '' || preg_match('#[\s/=]#', $key) === 1) {
				throw new InvalidArgumentException("{$key} isn't an attribute name");
			}

			if (is_array($value)) {
				$value = implode(' ', array_filter($value, fn(mixed $item): bool => !blank($item)));
			}

			if (blank($value) || $value === false) {
				continue;
			}

			if ($value === true) {
				$parts[] = e($key);
				continue;
			}

			$parts[] = sprintf('%s="%s"', e($key), e($value));
		}

		return implode(' ', $parts);
	}
}

if (!function_exists('blank')) {
	/**
	 * Whether the value is empty, meaning null, a blank string or an empty array or Countable. '0', 0 and false aren't blank. A Value has to be read as a type first, as in blank($value->string()).
	 */
	function blank(mixed $value): bool
	{
		if ($value instanceof Sapling\Value) {
			throw new LogicException('blank() was given a Value');
		}

		return match (true) {
			is_string($value), $value instanceof Stringable => trim((string) $value) === '',
			is_countable($value) => count($value) === 0,
			default => $value === null,
		};
	}
}

if (!function_exists('datetime')) {
	/**
	 * A DateTimeImmutable in $timezone, or in the default timezone. A date string without its own zone is read in $timezone.
	 *
	 * @throws DateInvalidTimeZoneException When $timezone isn't a known timezone.
	 * @throws DateMalformedStringException When $value is a string that isn't a date, such as a blank one, a timezone on its own or 30 February.
	 */
	function datetime(
		DateTimeInterface|int|float|string|null $value = null,
		DateTimeZone|string|null $timezone = null,
	): DateTimeImmutable {
		if (is_string($value)) {
			/** @var array{year: int|false, month: int|false, day: int|false, hour: int|false, is_localtime: bool, relative?: mixed} $parts */
			$parts = date_parse($value);
			[$year, $month, $day] = [$parts['year'], $parts['month'], $parts['day']];

			// Left to PHP, a blank string is now, 'x' is now in timezone X and 30 February is 2 March.
			$timeless = $month === false && $parts['hour'] === false && !array_key_exists('relative', $parts);
			$onlyZone = $timeless && $parts['is_localtime'];
			$noSuchDay = $year !== false && $month !== false && $day !== false && !checkdate($month, $day, $year);

			if (trim($value) === '' || $onlyZone || $noSuchDay) {
				throw new DateMalformedStringException("Not a date: {$value}");
			}
		}

		$zone = $timezone ?? date_default_timezone_get();
		if (is_string($zone)) {
			$zone = new DateTimeZone($zone);
		}

		$date = match (true) {
			$value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value),
			is_int($value), is_float($value) => DateTimeImmutable::createFromTimestamp($value),
			default => new DateTimeImmutable($value ?? 'now', $zone),
		};

		return $date->setTimezone($zone);
	}
}

if (!function_exists('dd')) {
	/**
	 * Dumps the values and stops.
	 */
	function dd(mixed $value, mixed ...$more): never
	{
		var_dump($value, ...$more);
		exit(1);
	}
}

if (!function_exists('e')) {
	/**
	 * Escapes a value for HTML. A backed enum prints its value. A Value has to be read as a type first, as in e($value->string()).
	 */
	function e(mixed $value): string
	{
		if ($value instanceof BackedEnum) {
			$value = $value->value;
		}

		return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}

if (!function_exists('value')) {
	/**
	 * Short for new Sapling\Value($raw).
	 */
	function value(mixed $raw): Sapling\Value
	{
		return new Sapling\Value($raw);
	}
}
