<?php

namespace Sapling;

use BackedEnum;
use Stringable;

/**
 * A value from outside the app, such as a form field, query parameter, cookie or command-line option, read as the
 * type you need. A value that's missing, or can't be read as that type, gives the default.
 */
final readonly class Value
{
	private mixed $plain;

	/**
	 * Takes the data as it arrived, with null for a value that's missing. $raw holds it unchanged.
	 */
	public function __construct(public mixed $raw)
	{
		if ($raw instanceof BackedEnum) {
			$raw = $raw->value;
		}

		$this->plain = $raw;
	}

	/**
	 * The value as a string. Numbers, Stringable objects and backed enums are cast, and a boolean gives the default.
	 */
	public function string(string $default = ''): string
	{
		if (is_string($this->plain)) {
			return $this->plain;
		}

		if (is_int($this->plain) || is_float($this->plain)) {
			return (string) $this->plain;
		}

		if ($this->plain instanceof Stringable) {
			return (string) $this->plain;
		}

		return $default;
	}

	/**
	 * The value as a whole number, so '12' is 12, while '12abc' and '1.5' give the default.
	 */
	public function int(int $default = 0): int
	{
		$int = $this->filter(FILTER_VALIDATE_INT);

		if (!is_int($int)) {
			return $default;
		}

		return $int;
	}

	/**
	 * The value as a number, so '1.5' is 1.5 and 'abc' gives the default.
	 */
	public function float(float $default = 0.0): float
	{
		$float = $this->filter(FILTER_VALIDATE_FLOAT);

		if (!is_float($float)) {
			return $default;
		}

		return $float;
	}

	/**
	 * The value as true or false. '1', 'true', 'on' and 'yes' are true, and '0', 'false', 'off', 'no' and '' are
	 * false.
	 */
	public function bool(bool $default = false): bool
	{
		if (is_bool($this->plain)) {
			return $this->plain;
		}

		$bool = $this->filter(FILTER_VALIDATE_BOOL);

		if (!is_bool($bool)) {
			return $default;
		}

		return $bool;
	}

	/**
	 * The value as an array, as sent by tags[]=a, or the default when it isn't one.
	 *
	 * @param array<array-key, mixed> $default
	 * @return array<array-key, mixed>
	 */
	public function array(array $default = []): array
	{
		if (!is_array($this->raw)) {
			return $default;
		}

		return $this->raw;
	}

	/**
	 * A key of an array or a public property of an object, as in get('address', 'city')->string(). A missing key gives
	 * an empty Value.
	 */
	public function get(string|int $key, string|int ...$more): self
	{
		$value = $this->child($key);

		foreach ($more as $next) {
			$value = $value->child($next);
		}

		return $value;
	}

	private function child(string|int $key): self
	{
		if (is_object($this->raw)) {
			return new self(get_object_vars($this->raw)[$key] ?? null);
		}

		if (is_array($this->raw)) {
			return new self($this->raw[$key] ?? null);
		}

		return new self(null);
	}

	// An array or null is never a valid number or boolean, and filter_var() would turn true into 1.
	private function filter(int $filter): int|float|bool|null
	{
		if (!is_string($this->plain) && !is_int($this->plain) && !is_float($this->plain)) {
			return null;
		}

		return filter_var($this->plain, $filter, FILTER_NULL_ON_FAILURE);
	}
}
