<?php

use Sapling\Http\Router;

test('routes:list shows method, path and class for every route', function () {
	$app = scannedApp();

	[$code, $output] = runInApp($app, 'routes:list');
	$lines = explode("\n", trim($output));

	expect($code)->toBe(0);
	expect($lines)->toHaveCount(count($app->container->get(Router::class)->all()));
	expect($output)->toMatch('#^GET\s+/guarded\s+Sapling\\\\Tests\\\\Fixtures\\\\Scan\\\\Guarded$#m');
	expect($output)->not->toMatch('/ +\n/');
});

test('the paths line up, however long the methods are', function () {
	$app = new Sapling\App(FIXTURES);
	$app->router->add('GET', '/notes', fn() => null);
	$app->router->add('OPTIONS', '/notes', fn() => null);

	[, $output] = runInApp($app, 'routes:list');

	expect($output)->toBe("GET      /notes  Closure\nOPTIONS  /notes  Closure\n");
});
