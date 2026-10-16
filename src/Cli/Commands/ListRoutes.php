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

	public function __invoke(Console $console): int
	{
		$routes = $this->router->all();
		$methodWidth = 0;
		$pathWidth = 0;

		foreach ($routes as $route) {
			$methodWidth = max($methodWidth, strlen($route['method']));
			$pathWidth = max($pathWidth, strlen($route['path']));
		}

		usort($routes, $this->byPathThenMethod(...));

		foreach ($routes as $route) {
			$action = $route['action'];

			if (is_object($action)) {
				$action = $action::class;
			}

			$console->line(sprintf(
				"%-{$methodWidth}s  %-{$pathWidth}s  %s",
				$route['method'],
				$route['path'],
				$action,
			));
		}

		return Console::SUCCESS;
	}

	/**
	 * @param array{method: string, path: string, action: class-string|object} $a
	 * @param array{method: string, path: string, action: class-string|object} $b
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
