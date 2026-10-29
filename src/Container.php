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
	 * The one shared instance of the class, built the first time. Constructor arguments come from their types, #[Config] or their defaults.
	 *
	 * @template T of object
	 * @param class-string<T> $id
	 * @return T
	 */
	public function get(string $id): object
	{
		return $this->instances[$id] ??= $this->build($id);
	}

	/**
	 * Registers an object, or a closure that builds it the first time it's asked for. Throws a LogicException once get() has handed the class out.
	 */
	public function set(string $id, object $value): void
	{
		if (array_key_exists($id, $this->instances)) {
			throw new \LogicException("Cannot replace {$id} after handing it out");
		}

		if ($value instanceof \Closure) {
			$this->factories[$id] = $value;
			return;
		}

		// A factory as well, so $instances holds only what get() has handed out.
		$this->factories[$id] = fn(): object => $value;
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
