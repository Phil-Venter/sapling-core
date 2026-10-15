<?php

namespace Sapling\Http;

use Generator;
use LogicException;

/**
 * Matches a method and path to a route. Routes live in a tree with a level per path segment, and a fixed segment is
 * tried before a :param at every level, so /notes/new wins over /notes/:id.
 */
final class Router
{
	private object $tree;

	public function __construct()
	{
		$this->tree = self::node();
	}

	/**
	 * Adds a route. The action is a class name, an object or a closure. Throws LogicException when the method and
	 * path are already taken.
	 *
	 * @param class-string|object $action
	 * @param list<class-string|object> $middleware
	 */
	public function add(string $method, string $path, string|object $action, array $middleware = []): void
	{
		$method = strtoupper($method);
		$segments = self::segments($path);
		$path = '/' . implode('/', $segments);
		$node = $this->tree;
		$params = [];

		foreach ($segments as $segment) {
			$node = self::branch($node, $segment);

			if (str_starts_with($segment, ':')) {
				$params[] = substr($segment, 1);
			}
		}

		$taken = $node->routes[$method]['action'] ?? null;

		if ($taken !== null) {
			$first = self::actionName($taken);
			$second = self::actionName($action);

			throw new LogicException("Duplicate route {$method} {$path}: {$first} and {$second}");
		}

		// @mago-expect analysis:ambiguous-object-property-access
		$node->routes[$method] = ['action' => $action, 'middleware' => $middleware, 'params' => $params];
	}

	/**
	 * The route for a method and path, with its :params.
	 *
	 * @return array{action: class-string|object, middleware: list<class-string|object>, params: array<string, string>}
	 * @throws HttpException 404 for an unknown path, or 405 with an Allow header for the wrong method.
	 */
	public function match(string $method, string $path): array
	{
		$allowed = [];

		foreach ($this->walk($this->tree, self::segments($path)) as [$node, $values]) {
			$route = $node->routes[$method] ?? null;

			if ($route === null && $method === 'HEAD') {
				$route = $node->routes['GET'] ?? null;
			}

			if ($route !== null) {
				$params = array_combine($route['params'], $values);

				// @mago-expect analysis:less-specific-nested-return-statement
				return ['action' => $route['action'], 'middleware' => $route['middleware'], 'params' => $params];
			}

			$allowed = [...$allowed, ...array_keys($node->routes)];
		}

		if ($allowed === []) {
			throw new HttpException(404);
		}

		if (in_array('GET', $allowed, true)) {
			$allowed[] = 'HEAD';
		}

		$allow = implode(', ', array_unique($allowed));

		throw new HttpException(405, '', ['Allow' => $allow]);
	}

	/**
	 * Every route, read back from the tree, fixed segments first.
	 *
	 * @return list<array{method: string, path: string, action: class-string|object}>
	 */
	public function all(): array
	{
		return iterator_to_array($this->routesBelow($this->tree), false);
	}

	/**
	 * @param list<string> $segments
	 * @param list<string> $values
	 * @return Generator<array{object, list<string>}>
	 */
	private function walk(object $node, array $segments, array $values = []): Generator
	{
		if ($segments === []) {
			yield [$node, $values];

			return;
		}

		$segment = array_shift($segments);
		$fixed = $node->fixed[$segment] ?? null;

		if ($fixed !== null) {
			yield from $this->walk($fixed, $segments, $values);
		}

		$value = rawurldecode($segment);

		// @mago-expect analysis:ambiguous-object-property-access
		if ($node->param !== null && $value !== '' && !str_contains($value, '/')) {
			yield from $this->walk($node->param, $segments, [...$values, $value]);
		}
	}

	/**
	 * @param list<string> $segments
	 * @return Generator<array{method: string, path: string, action: class-string|object}>
	 */
	private function routesBelow(object $node, array $segments = []): Generator
	{
		// @mago-expect analysis:ambiguous-object-property-access
		// @mago-expect analysis:invalid-iterator
		foreach ($node->routes as $method => $route) {
			$params = $route['params'];
			$parts = [];

			foreach ($segments as $segment) {
				if ($segment === ':') {
					$segment = ':' . array_shift($params);
				}

				$parts[] = $segment;
			}

			// @mago-expect analysis:invalid-yield-value-type
			yield ['method' => $method, 'path' => '/' . implode('/', $parts), 'action' => $route['action']];
		}

		// @mago-expect analysis:ambiguous-object-property-access
		// @mago-expect analysis:invalid-iterator
		foreach ($node->fixed as $segment => $branch) {
			yield from $this->routesBelow($branch, [...$segments, (string) $segment]);
		}

		// @mago-expect analysis:ambiguous-object-property-access
		if ($node->param !== null) {
			yield from $this->routesBelow($node->param, [...$segments, ':']);
		}
	}

	private static function node(): object
	{
		return (object) ['fixed' => [], 'param' => null, 'routes' => []];
	}

	private static function branch(object $node, string $segment): object
	{
		if (str_starts_with($segment, ':')) {
			// @mago-expect analysis:ambiguous-object-property-access
			return $node->param ??= self::node();
		}

		// @mago-expect analysis:ambiguous-object-property-access
		return $node->fixed[$segment] ??= self::node();
	}

	/** @return list<string> */
	private static function segments(string $path): array
	{
		return explode('/', trim($path, '/'));
	}

	private static function actionName(string|object $action): string
	{
		if (is_object($action)) {
			$action = $action::class;
		}

		return $action;
	}
}
