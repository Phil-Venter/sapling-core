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

if (!function_exists('attr')) {
	/**
	 * One HTML attribute, escaped, such as attr('class', ['note', 'pinned']) for class="note pinned". A value of true gives a bare attribute, and null, false or an empty value gives null.
	 */
	function attr(string $key, mixed $value = null): ?string
	{
		if (is_array($value)) {
			$value = implode(' ', array_filter($value, fn(mixed $item): bool => !blank($item)));
		}

		if (blank($value) || $value === false) {
			return null;
		}

		if ($value === true) {
			return e($key);
		}

		return sprintf('%s="%s"', e($key), e($value));
	}
}

if (!function_exists('attrs')) {
	/**
	 * attr() for each pair, joined with spaces. Empty ones are left out.
	 *
	 * @param array<string, mixed> $attrs
	 */
	function attrs(array $attrs): string
	{
		$parts = array_map(attr(...), array_keys($attrs), $attrs);
		return implode(' ', array_filter($parts, fn(?string $part): bool => $part !== null));
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

if (!function_exists('uuid')) {
	/**
	 * A version 7 UUID, which sorts by creation time.
	 */
	function uuid(): string
	{
		$time = str_pad(dechex((int) (microtime(true) * 1000)), 12, '0', STR_PAD_LEFT);
		$random = bin2hex(random_bytes(10));
		$variant = dechex(8 | (hexdec($random[3]) & 3));
		return sprintf(
			'%s-%s-7%s-%s%s-%s',
			substr($time, 0, 8),
			substr($time, 8, 4),
			substr($random, 0, 3),
			$variant,
			substr($random, 4, 3),
			substr($random, 7, 12),
		);
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
