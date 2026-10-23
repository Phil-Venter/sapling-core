<?php

use Sapling\App;
use Sapling\Http\HttpException;
use Sapling\Http\Request;
use Sapling\Http\Router;
use Sapling\Tests\Fixtures\Broken\DuplicateA;
use Sapling\Tests\Fixtures\Broken\DuplicateB;

test('a request reaches the action its path matches, or gets a 404', function (
	string $method,
	string $path,
	int $status,
	string $body,
) {
	$response = scannedApp()->handle(new Request($method, $path));

	expect([$response->status, $response->body])->toBe([$status, $body]);
})->with([
	'a static route, over a param route scanned before it' => ['GET', '/notes/new', 200, 'new note form'],
	'the param route' => ['GET', '/notes/7', 200, 'note 7'],
	'a URL-decoded param' => ['GET', '/notes/hello%20world', 200, 'note hello world'],
	'a trailing slash' => ['GET', '/notes/', 200, 'notes'],
	'HEAD, answered by the GET action' => ['HEAD', '/notes', 200, 'notes'],
	'an unknown path' => ['GET', '/nowhere', 404, 'Not Found'],
	'a slash in a param' => ['GET', '/notes/a/b', 404, 'Not Found'],
	'an encoded slash in a param' => ['GET', '/notes/a%2Fb', 404, 'Not Found'],
	'a lowercase encoded slash in a param' => ['GET', '/notes/a%2fb', 404, 'Not Found'],
]);

test('a wrong method is a 405 whose Allow header includes HEAD', function () {
	$response = scannedApp()->handle(new Request('PATCH', '/notes/7'));

	expect($response->status)->toBe(405);
	expect($response->body)->toBe('Method Not Allowed');
	expect($response->headers['Allow'])->toBe('DELETE, GET, HEAD');
});

test('the first fixed segment wins, whatever order routes were added in', function (array $order) {
	$router = new Router();

	foreach ($order as [$path, $action]) {
		$router->add('GET', $path, $action);
	}

	expect($router->match('GET', '/notes/edit')['action'])->toBe('ShowNote');
	expect($router->match('GET', '/notes/new')['action'])->toBe('NewNote');
	expect($router->match('GET', '/pages/edit')['action'])->toBe('EditSection');
	expect($router->match('GET', '/notes/7')['action'])->toBe('ShowNote');
})->with([
	'catch-all first' => [[
		['/:section/edit', 'EditSection'],
		['/notes/:id',     'ShowNote'],
		['/notes/new',     'NewNote'],
	]],
	'catch-all last' => [[
		['/notes/new',     'NewNote'],
		['/notes/:id',     'ShowNote'],
		['/:section/edit', 'EditSection'],
	]],
]);

test('a method only a :param route has still reaches it past a fixed route', function () {
	$router = new Router();
	$router->add('GET', '/notes/new', 'NewNote');
	$router->add('DELETE', '/notes/:id', 'DeleteNote');

	expect($router->match('DELETE', '/notes/new'))->toMatchArray([
		'action' => 'DeleteNote',
		'params' => ['id' => 'new'],
	]);
	expect(fn() => $router->match('PATCH', '/notes/new'))->toThrow(HttpException::class, 'Method Not Allowed');
});

test('a param never matches an empty segment', function () {
	$router = new Router();
	$router->add('GET', '/:slug', 'Page');

	expect(fn() => $router->match('GET', '/'))->toThrow(HttpException::class, 'Not Found');
});

test('the same method and path on two classes throws at boot and names both', function () {
	$app = new App(FIXTURES);
	$app->add(DuplicateA::class);

	expect(fn() => $app->add(DuplicateB::class))
		->toThrow(
			LogicException::class,
			'Duplicate route GET /dup/:slug: ' . DuplicateA::class . ' and ' . DuplicateB::class,
		);
});

test('all() lists every route with its own :param names', function () {
	$router = new Router();
	$router->add('GET', '/', 'Home');
	$router->add('GET', '/notes/:id', 'ShowNote');
	$router->add('DELETE', '/notes/:note', 'DeleteNote');
	$router->add('GET', '/notes/new', 'NewNote');
	$router->add('GET', '/notes/:id/files/:name', 'ShowFile');
	$router->add('GET', '/ping', fn() => null);

	expect($router->all())->toBe([
		['method' => 'GET', 'path' => '/', 'action' => 'Home'],
		['method' => 'GET', 'path' => '/notes/new', 'action' => 'NewNote'],
		['method' => 'GET', 'path' => '/notes/:id', 'action' => 'ShowNote'],
		['method' => 'DELETE', 'path' => '/notes/:note', 'action' => 'DeleteNote'],
		['method' => 'GET', 'path' => '/notes/:id/files/:name', 'action' => 'ShowFile'],
		['method' => 'GET', 'path' => '/ping', 'action' => 'Closure'],
	]);
});

test('a parameter name used twice, or one that is not a plain name, throws', function (string $path, string $message) {
	$router = new Router();

	expect(fn() => $router->add('GET', $path, 'Handler'))->toThrow(LogicException::class, $message);
})->with([
	'used twice' => ['/a/:id/b/:id', 'Route GET /a/:id/b/:id uses :id twice'],
	'not a plain name' => ['/files/:name.:ext', "Route GET /files/:name.:ext: :name.:ext isn't a parameter name"],
]);
