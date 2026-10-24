<?php

declare(strict_types=1);

namespace Sapling;

/**
 * Holds shared objects, and builds classes from the types of their constructor parameters.
 */
final class Container
{
	/** @var array<string, object> */
	private array $instances = [];

	/** @var array<string, \Closure(self): object> */
	private array $factories = [];

	/**
	 * The classes being built, outermost first, to catch two that need each other.
	 *
	 * @var array<string, true>
	 */
	private array $building = [];

	/**
	 * For each class, the classes built with it, so set() can refuse to replace what they hold.
	 *
	 * @var array<string, list<string>>
	 */
	private array $dependents = [];

	/**
	 * The one shared instance of the class, built the first time. Constructor arguments come from their types,
	 * #[Config] or their defaults.
	 *
	 * @template T of object
	 * @param class-string<T> $id
	 * @return T
	 */
	public function get(string $id): object
	{
		$dependent = array_key_last($this->building);

		if ($dependent !== null) {
			$this->dependents[$id][] = $dependent;
		}

		if (array_key_exists($id, $this->instances)) {
			return $this->instances[$id];
		}

		$this->instances[$id] = $this->build($id);

		return $this->instances[$id];
	}

	/**
	 * Registers an object, or a closure that builds it the first time it's asked for. Throws a LogicException once a
	 * class has been built with the one it would replace.
	 */
	public function set(string $id, object $value): void
	{
		$built = array_filter($this->dependents[$id] ?? [], fn(string $dependent): bool => array_key_exists(
			$dependent,
			$this->instances,
		));

		if ($built !== []) {
			$list = implode(', ', array_unique($built));

			throw new \LogicException("Cannot replace {$id} after building {$list} with it");
		}

		unset($this->instances[$id], $this->factories[$id]);

		if ($value instanceof \Closure) {
			$this->factories[$id] = $value;

			return;
		}

		$this->instances[$id] = $value;
	}

	/** @param class-string $id */
	private function build(string $id): object
	{
		if (array_key_exists($id, $this->building)) {
			$loop = implode(' -> ', [...array_keys($this->building), $id]);

			throw new \LogicException("Circular dependency: {$loop}");
		}

		$this->building[$id] = true;
		$arguments = [];

		try {
			if (array_key_exists($id, $this->factories)) {
				return $this->factories[$id]($this);
			}

			$reflection = new \ReflectionClass($id);
			$parameters = $reflection->getConstructor()?->getParameters() ?? [];

			foreach ($parameters as $parameter) {
				$arguments[] = $this->argument($parameter, $id);
			}
		} finally {
			unset($this->building[$id]);
		}

		// Not newInstanceArgs(), which converts loosely, so a setting of 'false' would fill a bool with true.
		// @mago-expect analysis:unknown-class-instantiation
		return new $id(...$arguments);
	}

	private function argument(\ReflectionParameter $parameter, string $id): mixed
	{
		$attribute = $parameter->getAttributes(Attributes\Config::class)[0] ?? null;

		if ($attribute !== null) {
			$key = $attribute->newInstance()->key;
			$config = $this->get(Config::class);

			if ($parameter->isDefaultValueAvailable()) {
				return $config->get($key, $parameter->getDefaultValue());
			}

			return $config->get($key);
		}

		$type = $parameter->getType();

		if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
			return $this->get($type->getName());
		}

		if ($parameter->isDefaultValueAvailable()) {
			return $parameter->getDefaultValue();
		}

		throw new \LogicException("Cannot autowire \${$parameter->getName()} in {$id}");
	}
}
