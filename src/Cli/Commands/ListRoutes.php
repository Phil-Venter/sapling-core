<?php

namespace Sapling\Cli\Commands;

use Sapling\Cli\Console;
use Sapling\Http\Router;

/**
 * Lists every route with its method and class, sorted by path.
 */
final readonly class ListRoutes
{
	public const string NAME = 'routes:list';

	public const string DESCRIPTION = 'List every route and its class';

	private const array METHOD_ORDER = ['HEAD' => 1, 'GET' => 2, 'POST' => 3, 'PUT' => 4, 'PATCH' => 5, 'DELETE' => 6];

	public function __construct(private Router $router) {}

	/**
	 * Prints every route, sorted by path and then method.
	 */
	public function __invoke(Console $console): int
	{
		$routes = $this->router->all();
		usort($routes, $this->byPathThenMethod(...));

		$rows = [];

		foreach ($routes as $route) {
			$rows[] = [$route['method'], $route['path'], $route['action']];
		}

		$console->table($rows);

		return Console::SUCCESS;
	}

	/**
	 * @param array{method: string, path: string, action: string} $a
	 * @param array{method: string, path: string, action: string} $b
	 */
	private function byPathThenMethod(array $a, array $b): int
	{
		$byPath = strnatcmp($a['path'], $b['path']);

		if ($byPath !== 0) {
			return $byPath;
		}

		return (self::METHOD_ORDER[$a['method']] ?? 99) <=> (self::METHOD_ORDER[$b['method']] ?? 99);
	}
}
