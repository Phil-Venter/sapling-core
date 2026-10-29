<?php

namespace Sapling\Http;

/**
 * Matches a method and path to a route. A fixed segment wins over a :param at the same level, so /notes/new matches
 * before /notes/:id.
 */
final class Router
{
	private RouteNode $tree;

	public function __construct()
	{
		$this->tree = new RouteNode();
	}

	/**
	 * Throws a LogicException when the method and path are already taken, or a :param name is used twice or isn't a
	 * plain name.
	 *
	 * @param class-string|object $action
	 * @param list<class-string|object> $middleware
	 */
	public function add(string $method, string $path, string|object $action, array $middleware = []): void
	{
		$method = strtoupper($method);
		$segments = explode('/', trim($path, '/'));
		$path = '/' . implode('/', $segments);
		$node = $this->tree;
		$params = [];
		foreach ($segments as $segment) {
			if (!str_starts_with($segment, ':')) {
				$node->fixed[$segment] ??= new RouteNode();
				$node = $node->fixed[$segment];
				continue;
			}

			$node->param ??= new RouteNode();
			$node = $node->param;
			$param = substr($segment, 1);
			if (in_array($param, $params, true)) {
				throw new \LogicException("Route {$method} {$path} uses :{$param} twice");
			}

			if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $param) !== 1) {
				throw new \LogicException("Route {$method} {$path}: {$segment} isn't a parameter name");
			}

			$params[] = $param;
		}

		$taken = $node->routes[$method]['action'] ?? null;
		if ($taken !== null) {
			$first = is_object($taken) ? $taken::class : $taken;
			$second = is_object($action) ? $action::class : $action;
			throw new \LogicException("Duplicate route {$method} {$path}: {$first} and {$second}");
		}

		$node->routes[$method] = [
			'action' => $action,
			'middleware' => $middleware,
			'params' => $params,
			'path' => $path,
		];
	}

	/**
	 * @return array{action: class-string|object, middleware: list<class-string|object>, params: array<string, string>}
	 * @throws HttpException 404 for an unknown path, or 405 with an Allow header for the wrong method.
	 */
	public function match(string $method, string $path): array
	{
		$allowed = [];
		$segments = explode('/', trim($path, '/'));
		foreach ($this->walk($this->tree, $segments) as [$node, $values]) {
			$route = $node->routes[$method] ?? null;
			if ($route === null && $method === 'HEAD') {
				$route = $node->routes['GET'] ?? null;
			}

			if ($route !== null) {
				$params = array_combine($route['params'], $values);
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
		throw new HttpException(405, headers: ['Allow' => $allow]);
	}

	/**
	 * Fixed segments come first, and each action is given as its class name.
	 *
	 * @return list<array{method: string, path: string, action: string}>
	 */
	public function all(): array
	{
		return iterator_to_array($this->routesBelow($this->tree), false);
	}

	/**
	 * @param list<string> $segments
	 * @param list<string> $values
	 * @return \Generator<array{RouteNode, list<string>}>
	 */
	private function walk(RouteNode $node, array $segments, array $values = []): \Generator
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
		if ($node->param !== null && $value !== '' && !str_contains($value, '/')) {
			yield from $this->walk($node->param, $segments, [...$values, $value]);
		}
	}

	/** @return \Generator<array{method: string, path: string, action: string}> */
	private function routesBelow(RouteNode $node): \Generator
	{
		foreach ($node->routes as $method => $route) {
			$action = is_object($route['action']) ? $route['action']::class : $route['action'];
			yield ['method' => $method, 'path' => $route['path'], 'action' => $action];
		}

		foreach ($node->fixed as $branch) {
			yield from $this->routesBelow($branch);
		}

		if ($node->param !== null) {
			yield from $this->routesBelow($node->param);
		}
	}
}
