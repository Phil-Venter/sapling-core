<?php

namespace Sapling\Cli;

/**
 * The registered commands, by name.
 */
final class CommandList
{
	/** @var array<string, array{action: class-string|object, description: string}> */
	private array $commands = [];

	/**
	 * Throws a LogicException when the name is taken.
	 *
	 * @param class-string|object $action
	 */
	public function add(string $name, string|object $action, string $description = ''): void
	{
		if (array_key_exists($name, $this->commands)) {
			$taken = $this->commands[$name]['action'];
			$first = is_object($taken) ? $taken::class : $taken;
			$second = is_object($action) ? $action::class : $action;

			throw new \LogicException("Duplicate command {$name}: {$first} and {$second}");
		}

		$this->commands[$name] = ['action' => $action, 'description' => $description];
	}

	/** @return class-string|object|null */
	public function find(string $name): string|object|null
	{
		return $this->commands[$name]['action'] ?? null;
	}

	/**
	 * Sorted by name.
	 *
	 * @return array<string, array{action: class-string|object, description: string}>
	 */
	public function all(): array
	{
		$commands = $this->commands;
		ksort($commands);

		return $commands;
	}
}
