<?php

namespace Sapling\Cli;

use Sapling\Value;

/**
 * A command's arguments, options and flags, and its output.
 */
final readonly class Console
{
	public const int SUCCESS = 0;

	public const int FAILURE = 1;

	public string $command;

	/** @var list<string> */
	public array $args;

	/** @var array<string, string> */
	private array $options;

	/** @var array<string, true> */
	private array $flags;

	/** @var resource|null */
	private mixed $input;

	/**
	 * The first word is the command, --name=value is an option, --name is a flag and the rest are arguments, as is
	 * every word after --. Tests can pass $input to stand in for standard input.
	 *
	 * @param list<string> $argv
	 * @param resource|null $input
	 */
	public function __construct(array $argv, mixed $input = null)
	{
		$command = '';
		$args = [];
		$options = [];
		$flags = [];
		$words = array_slice($argv, 1);
		$literal = [];
		$end = array_search('--', $words, true);
		if ($end !== false) {
			$literal = array_slice($words, $end + 1);
			$words = array_slice($words, 0, $end);
		}

		foreach ($words as $word) {
			if (str_starts_with($word, '--')) {
				$name = substr($word, 2);
				$equals = strpos($name, '=');
				if ($equals === false) {
					$flags[$name] = true;
					continue;
				}

				$options[substr($name, 0, $equals)] = substr($name, $equals + 1);
				continue;
			}

			if ($command === '') {
				$command = $word;
				continue;
			}

			$args[] = $word;
		}

		$this->command = $command;
		$this->args = [...$args, ...$literal];
		$this->options = $options;
		$this->flags = $flags;
		$this->input = $input;
	}

	/**
	 * The argument at $index, counting from 0 after the command.
	 */
	public function arg(int $index): Value
	{
		return new Value($this->args[$index] ?? null);
	}

	/**
	 * The value of --name=value.
	 *
	 * @throws UsageException When --name was given without =value, as in --days 7.
	 */
	public function option(string $name): Value
	{
		if (array_key_exists($name, $this->flags)) {
			throw new UsageException("--{$name} needs a value, written --{$name}=value");
		}

		return new Value($this->options[$name] ?? null);
	}

	/**
	 * Whether --name was given.
	 *
	 * @throws UsageException When it was given a value, as in --force=yes.
	 */
	public function flag(string $name): bool
	{
		if (array_key_exists($name, $this->options)) {
			throw new UsageException("--{$name} is a flag and takes no value");
		}

		return array_key_exists($name, $this->flags);
	}

	/**
	 * A blank answer or the end of input gives an empty Value.
	 */
	public function ask(string $question): Value
	{
		echo $question, ' ';
		$line = fgets($this->input ?? STDIN);

		// At the end of input, the cursor is still after the question.
		if ($line === false) {
			echo PHP_EOL;
			return new Value(null);
		}

		$answer = trim($line);
		if ($answer === '') {
			return new Value(null);
		}

		return new Value($answer);
	}

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

	/**
	 * Prints rows as columns, lined up and two spaces apart. An empty row prints a blank line.
	 *
	 * @param list<list<string>> $rows
	 */
	public function table(array $rows): void
	{
		$widths = [];
		foreach ($rows as $row) {
			foreach ($row as $column => $cell) {
				$widths[$column] = max($widths[$column] ?? 0, (int) preg_match_all('/./su', $cell));
			}
		}

		foreach ($rows as $row) {
			$cells = [];
			foreach ($row as $column => $cell) {
				$padding = ($widths[$column] ?? 0) - (int) preg_match_all('/./su', $cell);
				$cells[] = $cell . str_repeat(' ', max(0, $padding));
			}

			$this->line(rtrim(implode('  ', $cells)));
		}
	}
}
