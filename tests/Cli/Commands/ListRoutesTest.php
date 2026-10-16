<?php

use Sapling\Cli\Commands\ListRoutes;
use Sapling\Http\Router;

test('routes lists method, path and class for every route', function () {
	$app = scannedApp();
	$app->commands->add('routes:list', ListRoutes::class);

	[$code, $output] = runInApp($app, 'routes:list');
	$lines = explode("\n", trim($output));

	expect($code)->toBe(0);
	expect($lines)->toHaveCount(count($app->container->get(Router::class)->all()));
	expect($output)->toMatch('#^GET\s+/guarded\s+Sapling\\\\Tests\\\\Fixtures\\\\Scan\\\\Guarded$#m');
	expect($output)->not->toMatch('/ +\n/');
});

test('the paths line up, however long the methods are', function () {
	$app = new Sapling\App(FIXTURES);
	$app->commands->add('routes:list', ListRoutes::class);
	$app->router->add('GET', '/notes', fn() => null);
	$app->router->add('OPTIONS', '/notes', fn() => null);

	[, $output] = runInApp($app, 'routes:list');

	expect($output)->toBe("GET      /notes  Closure\nOPTIONS  /notes  Closure\n");
});
