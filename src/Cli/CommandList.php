<?php

namespace Sapling\Cli;

use LogicException;

/**
 * The registered commands, by name.
 */
final class CommandList
{
	/** @var array<string, array{action: class-string|object, description: string}> */
	private array $commands = [];

	/**
	 * Adds a command. The action is a class name, an object or a closure. Throws LogicException when the name is
	 * taken.
	 *
	 * @param class-string|object $action
	 */
	public function add(string $name, string|object $action, string $description = ''): void
	{
		if (array_key_exists($name, $this->commands)) {
			$first = $this->actionName($this->commands[$name]['action']);
			$second = $this->actionName($action);

			throw new LogicException("Duplicate command {$name}: {$first} and {$second}");
		}

		$this->commands[$name] = ['action' => $action, 'description' => $description];
	}

	/**
	 * The command's action, or null.
	 *
	 * @return class-string|object|null
	 */
	public function find(string $name): string|object|null
	{
		return $this->commands[$name]['action'] ?? null;
	}

	/**
	 * Every command, sorted by name.
	 *
	 * @return array<string, array{action: class-string|object, description: string}>
	 */
	public function all(): array
	{
		$commands = $this->commands;
		ksort($commands);

		return $commands;
	}

	private function actionName(string|object $action): string
	{
		if (is_object($action)) {
			$action = $action::class;
		}

		return $action;
	}
}
