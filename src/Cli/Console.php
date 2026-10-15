<?php

namespace Sapling\Cli;

use InvalidArgumentException;
use Sapling\Value;

/**
 * A command's arguments, options and flags, and its output.
 */
final class Console
{
	/** The exit code for a command that worked. */
	public const int SUCCESS = 0;

	/** The exit code for a command that failed. */
	public const int FAILURE = 1;

	public readonly string $command;

	/** @var list<string> */
	private array $args = [];

	/** @var array<string, string> */
	private array $options = [];

	/** @var array<string, true> */
	private array $flags = [];

	/**
	 * Reads argv. The first word is the command, --name=value is an option, --name is a flag and the rest are
	 * arguments, as is every word after --.
	 *
	 * @param list<string> $argv
	 */
	public function __construct(array $argv)
	{
		$command = '';
		$words = array_slice($argv, 1);
		$literal = [];
		$end = array_search('--', $words, true);

		if ($end !== false) {
			$literal = array_slice($words, $end + 1);
			$words = array_slice($words, 0, $end);
		}

		foreach ($words as $word) {
			if (str_starts_with($word, '--') && str_contains($word, '=')) {
				$parts = explode('=', substr($word, 2), 2);
				$this->options[$parts[0]] = $parts[1] ?? '';
				continue;
			}

			if (str_starts_with($word, '--')) {
				$this->flags[substr($word, 2)] = true;
				continue;
			}

			if ($command === '') {
				$command = $word;
				continue;
			}

			$this->args[] = $word;
		}

		$this->command = $command;
		$this->args = [...$this->args, ...$literal];
	}

	/**
	 * The argument at $index after the command, counting from 0, as in arg(0)->string().
	 */
	public function arg(int $index): Value
	{
		return new Value($this->args[$index] ?? null);
	}

	/**
	 * The value of --name=value, as in option('days')->int(30).
	 *
	 * @throws InvalidArgumentException When --name was given without =value, as in --days 7.
	 */
	public function option(string $name): Value
	{
		// --days 7 would otherwise quietly give the default.
		if (array_key_exists($name, $this->flags)) {
			throw new InvalidArgumentException("--{$name} needs a value, written --{$name}=value");
		}

		return new Value($this->options[$name] ?? null);
	}

	/**
	 * Whether --name was given.
	 *
	 * @throws InvalidArgumentException When it was given a value, as in --force=yes.
	 */
	public function flag(string $name): bool
	{
		if (array_key_exists($name, $this->options)) {
			throw new InvalidArgumentException("--{$name} is a flag and takes no value");
		}

		return array_key_exists($name, $this->flags);
	}

	/**
	 * Prints a line to standard output.
	 */
	public function line(string $text = ''): void
	{
		echo $text, PHP_EOL;
	}

	/**
	 * Prints a line to standard error.
	 */
	public function error(string $text): void
	{
		fwrite(STDERR, $text . PHP_EOL);
	}
}
