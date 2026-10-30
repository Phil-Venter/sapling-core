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
	 * Escaped HTML attributes, such as attrs(['class' => ['note', 'pinned']]) for class="note pinned". A value of true gives a bare attribute, and null, false or an empty value leaves the attribute out.
	 *
	 * @param array<string, mixed> $attrs
	 */
	function attrs(array $attrs): string
	{
		$parts = [];
		foreach ($attrs as $key => $value) {
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
	 * @throws DateMalformedStringException When $value is a string PHP can't read as a date.
	 */
	function datetime(
		DateTimeInterface|int|float|string|null $value = null,
		DateTimeZone|string|null $timezone = null,
	): DateTimeImmutable {
		// new DateTimeImmutable('') is now, which would turn a blank form field into the current time.
		if (is_string($value) && trim($value) === '') {
			throw new DateMalformedStringException('A blank string is not a date');
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
		// @mago-expect lint:no-debug-symbols
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
