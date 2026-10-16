<?php

use Sapling\App;
use Sapling\Http\HttpException;
use Sapling\Http\Request;
use Sapling\Http\Router;
use Sapling\Tests\Fixtures\Broken\DuplicateA;
use Sapling\Tests\Fixtures\Broken\DuplicateB;

beforeEach(function () {
	$this->globals = [$_SERVER, $_POST];
});

afterEach(function () {
	[$_SERVER, $_POST] = $this->globals;
});

test('a static route beats a param route that was scanned before it', function () {
	$app = scannedApp();
	expect($app->handle(new Request('GET', '/notes/new'))->body)->toBe('new note form');
	expect($app->handle(new Request('GET', '/notes/7'))->body)->toBe('note 7');
});

test('an unknown path is a 404', function () {
	$response = scannedApp()->handle(new Request('GET', '/nowhere'));

	expect($response->status)->toBe(404);
	expect($response->body)->toBe('Not Found');
});

test('a known path with the wrong method is a 405 with an Allow header that includes HEAD', function () {
	$response = scannedApp()->handle(new Request('PATCH', '/notes/7'));

	expect($response->status)->toBe(405);
	expect($response->body)->toBe('Method Not Allowed');
	expect($response->headers['Allow'])->toBe('DELETE, GET, HEAD');
});

test('HEAD on a GET route is answered by the GET action', function () {
	$response = scannedApp()->handle(new Request('HEAD', '/notes'));

	expect($response->status)->toBe(200);
	expect($response->headers['Content-Type'])->toBe('text/plain; charset=utf-8');
});

test('trailing slashes do not matter', function () {
	expect(scannedApp()->handle(new Request('GET', '/notes/'))->body)->toBe('notes');
});

test('params are URL-decoded and never hold a slash, encoded or not', function () {
	$app = scannedApp();

	expect($app->handle(new Request('GET', '/notes/hello%20world'))->body)->toBe('note hello world');
	expect($app->handle(new Request('GET', '/notes/a%2Fb'))->status)->toBe(404);
	expect($app->handle(new Request('GET', '/notes/a%2fb'))->status)->toBe(404);
	expect($app->handle(new Request('GET', '/notes/a/b'))->status)->toBe(404);
});

test('left to right, the first literal segment wins, whatever order routes were added in', function (array $order) {
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

test('all() reads every route back from the tree, with each route\'s own :param names', function () {
	$router = new Router();
	$router->add('GET', '/', 'Home');
	$router->add('GET', '/notes/:id', 'ShowNote');
	$router->add('DELETE', '/notes/:note', 'DeleteNote');
	$router->add('GET', '/notes/new', 'NewNote');
	$router->add('GET', '/notes/:id/files/:name', 'ShowFile');

	expect($router->all())->toBe([
		['method' => 'GET', 'path' => '/', 'action' => 'Home'],
		['method' => 'GET', 'path' => '/notes/new', 'action' => 'NewNote'],
		['method' => 'GET', 'path' => '/notes/:id', 'action' => 'ShowNote'],
		['method' => 'DELETE', 'path' => '/notes/:note', 'action' => 'DeleteNote'],
		['method' => 'GET', 'path' => '/notes/:id/files/:name', 'action' => 'ShowFile'],
	]);
});

test('a parameter name used twice, or one that is not a plain name, throws', function (string $path, string $message) {
	$router = new Router();

	expect(fn() => $router->add('GET', $path, 'Handler'))->toThrow(LogicException::class, $message);
})->with([
	'used twice' => ['/a/:id/b/:id', 'Route GET /a/:id/b/:id uses :id twice'],
	'not a plain name' => ['/files/:name.:ext', "Route GET /files/:name.:ext: :name.:ext isn't a parameter name"],
]);
