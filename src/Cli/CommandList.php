<?php

namespace Sapling\Cli;

/**
 * The registered commands, by name.
 */
final class CommandList
{
	/** @var array<string, array{action: class-string|object, description: string, options: list<string>}> */
	private array $commands = [];

	/**
	 * $options names the options and flags the command takes. Throws a LogicException when the name is taken.
	 *
	 * @param class-string|object $action
	 * @param list<string> $options
	 */
	public function add(string $name, string|object $action, string $description = '', array $options = []): void
	{
		if (!array_key_exists($name, $this->commands)) {
			$this->commands[$name] = ['action' => $action, 'description' => $description, 'options' => $options];
			return;
		}

		$taken = $this->commands[$name]['action'];
		$first = is_object($taken) ? $taken::class : $taken;
		$second = is_object($action) ? $action::class : $action;

		throw new \LogicException("Duplicate command {$name}: {$first} and {$second}");
	}

	/** @return array{action: class-string|object, description: string, options: list<string>}|null */
	public function find(string $name): ?array
	{
		return $this->commands[$name] ?? null;
	}

	/**
	 * Sorted by name.
	 *
	 * @return array<string, array{action: class-string|object, description: string, options: list<string>}>
	 */
	public function all(): array
	{
		$commands = $this->commands;
		ksort($commands);
		return $commands;
	}
}
