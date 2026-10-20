<?php

namespace Sapling;

/**
 * A value from outside the app, such as a form field, query parameter, cookie or command-line option, read as the
 * type you need. A value that's missing, or can't be read as that type, gives the default.
 */
final readonly class Value
{
	public mixed $raw;

	/**
	 * Null means the value is missing. A Value passed in is unwrapped.
	 */
	public function __construct(mixed $raw)
	{
		if ($raw instanceof self) {
			$raw = $raw->raw;
		}

		$this->raw = $raw;
	}

	/**
	 * Casts numbers, Stringable objects and backed enums. A boolean gives the default.
	 */
	public function string(string $default = ''): string
	{
		return $this->text() ?? $default;
	}

	/**
	 * '12' is 12, while '12abc' and '1.5' give the default.
	 */
	public function int(int $default = 0): int
	{
		$int = $this->filter(FILTER_VALIDATE_INT);

		if (!is_int($int)) {
			return $default;
		}

		return $int;
	}

	public function float(float $default = 0.0): float
	{
		$float = $this->filter(FILTER_VALIDATE_FLOAT);

		if (!is_float($float)) {
			return $default;
		}

		return $float;
	}

	/**
	 * '1', 'true', 'on' and 'yes' are true, and '0', 'false', 'off', 'no' and '' are false.
	 */
	public function bool(bool $default = false): bool
	{
		if (is_bool($this->raw)) {
			return $this->raw;
		}

		$bool = $this->filter(FILTER_VALIDATE_BOOL);

		if (!is_bool($bool)) {
			return $default;
		}

		return $bool;
	}

	/**
	 * The default picks the enum. enum(Sort::Newest) reads 'oldest' as Sort::Oldest and anything else as
	 * Sort::Newest. An int-backed enum reads the value as int() does.
	 *
	 * @template T of \BackedEnum
	 * @param T $default
	 * @return T
	 */
	public function enum(\BackedEnum $default): \BackedEnum
	{
		$value = $this->text();

		if (is_int($default->value)) {
			$value = $this->filter(FILTER_VALIDATE_INT);
		}

		if (!is_int($value) && !is_string($value)) {
			return $default;
		}

		/** @var T|null $case */
		$case = $default::tryFrom($value);

		return $case ?? $default;
	}

	/**
	 * Each item as a Value, under its key. Anything that isn't an array gives [].
	 *
	 * @return array<array-key, self>
	 */
	public function items(): array
	{
		return array_map(fn(mixed $item): self => new self($item), $this->rawArray());
	}

	/**
	 * An array key or public property. More keys read deeper, and a missing one gives an empty Value.
	 */
	public function get(string|int ...$keys): self
	{
		$value = $this->raw;

		foreach ($keys as $key) {
			if (is_object($value)) {
				$value = get_object_vars($value);
			}

			if (!is_array($value)) {
				return new self(null);
			}

			$value = $value[$key] ?? null;
		}

		return new self($value);
	}

	/**
	 * The array as it arrived, with its items unchecked, or the default when it isn't one.
	 *
	 * @param array<array-key, mixed> $default
	 * @return array<array-key, mixed>
	 */
	public function rawArray(array $default = []): array
	{
		if (!is_array($this->raw)) {
			return $default;
		}

		return $this->raw;
	}

	private function text(): ?string
	{
		if ($this->raw instanceof \BackedEnum) {
			return (string) $this->raw->value;
		}

		if (is_string($this->raw) || is_int($this->raw) || is_float($this->raw) || $this->raw instanceof \Stringable) {
			return (string) $this->raw;
		}

		return null;
	}

	private function filter(int $filter): int|float|bool|null
	{
		$text = $this->text();

		if ($text === null) {
			return null;
		}

		return filter_var($text, $filter, FILTER_NULL_ON_FAILURE);
	}
}
