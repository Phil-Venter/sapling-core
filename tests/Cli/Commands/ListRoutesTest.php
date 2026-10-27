<?php

use Sapling\App;
use Sapling\Http\Router;

test('routes:list shows method, path and class for every route', function () {
	$app = scannedApp();

	[$code, $output] = runInApp($app, 'routes:list');
	$lines = explode("\n", trim($output));

	expect($code)->toBe(0);
	expect($lines)->toHaveCount(count($app->container->get(Router::class)->all()));
	expect($output)->toMatch('#^GET\s+/guarded\s+Sapling\\\\Tests\\\\Fixtures\\\\Scan\\\\Guarded$#m');
});

test('routes:list sorts by path, then by method', function () {
	$app = new App(FIXTURES);
	$app->router->add('GET', '/notes/10', fn() => null);
	$app->router->add('OPTIONS', '/notes', fn() => null);
	$app->router->add('DELETE', '/notes', fn() => null);
	$app->router->add('POST', '/notes', fn() => null);
	$app->router->add('GET', '/notes/2', fn() => null);
	$app->router->add('GET', '/notes', fn() => null);

	[, $output] = runInApp($app, 'routes:list');

	$expected = <<<'TXT'
		GET      /notes     Closure
		POST     /notes     Closure
		DELETE   /notes     Closure
		OPTIONS  /notes     Closure
		GET      /notes/2   Closure
		GET      /notes/10  Closure

		TXT;

	expect($output)->toBe($expected);
});
