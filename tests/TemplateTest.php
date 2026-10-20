<?php

use Sapling\Template;

beforeEach(function () {
	$this->view = new Template(FIXTURES . '/views');
	$this->view->share('site', 'Sapling');
});

test('a page in a layout component fills its slots', function () {
	$html = $this->view->render('page', ['name' => 'Ann']);

	expect($html)
		->toContain('<title>Page Ann</title>')
		->toMatch('#<main>\s*<p>Hello Ann</p>\s*</main>#')
		->toContain('<footer>Sapling</footer>');
});

test('shared data reaches every template, partials included, and call data wins', function () {
	$list = $this->view->render('list', ['items' => ['a', 'b']]);
	$plain = $this->view->render('plain', ['name' => 'Ann', 'site' => 'Override']);

	expect($list)->toContain('<li>a (Sapling)</li>')->toContain('<li>b (Sapling)</li>');
	expect($plain)->toBe("<p>Ann on Override</p>\n");
});

test('layouts can nest and pass named slots on', function () {
	expect($this->view->render('child'))
		->toContain('<title>Child</title>')
		->toMatch('#<main>\s*<section>\s*<p>child body</p>\s*</section>\s*</main>#');
});

test('named slots do not leak into the next page', function () {
	$this->view->render('page', ['name' => 'Ann']);

	expect($this->view->render('untitled'))->toContain('<title>Default</title>');
});

test('an error inside a template leaves no buffers open', function () {
	$level = ob_get_level();

	expect(fn() => $this->view->render('broken'))->toThrow(RuntimeException::class, 'template failed');
	expect(ob_get_level())->toBe($level);
	expect($this->view->render('untitled'))->toContain('<title>Default</title>');
});

test('a misused component or slot throws', function () {
	$level = ob_get_level();

	expect(fn() => $this->view->render('unclosed'))
		->toThrow(LogicException::class, 'Component _card in unclosed has no end()');
	expect(fn() => $this->view->render('stray-end'))
		->toThrow(LogicException::class, 'end() without an open slot() or component()');
	expect(fn() => $this->view->render('stray-slot'))->toThrow(LogicException::class, 'slot() outside a component()');
	expect(fn() => $this->view->render('closes-parent'))
		->toThrow(LogicException::class, "end() in _closes closes a component or slot it didn't open");
	expect(ob_get_level())->toBe($level);
	expect($this->view->render('plain', ['name' => 'Ann']))->toBe("<p>Ann on Sapling</p>\n");
});

test('components and slots print exactly what they were given', function (string $template, string $expected) {
	expect($this->view->render($template))->toBe($expected);
})->with([
	'a component, with its data and default slot' => [
		'card-page',
		"<article><h2>Notes</h2><p>Inside</p>\n</article>\n",
	],
	'slot() with a value, such as a list' => [
		'scripts-page',
		'<script src="/a.js"></script><script src="/b.js"></script>',
	],
	'a slot or page that prints only 0' => ['zero', '0'],
]);

test('prefix::name finds a template in the folder registered with prefix()', function () {
	$this->view->prefix('mine', FIXTURES . '/views/colocated/');

	expect($this->view->render('mine::card', ['name' => 'Ann']))->toBe("<p>colocated Ann</p>\n");
	expect(fn() => $this->view->render('other::card'))
		->toThrow(LogicException::class, 'Unknown template prefix other in other::card');
});

test('templates end in .tmpl.php unless another suffix is given', function () {
	$view = new Template(FIXTURES . '/views', suffix: 'php');

	expect($view->render('legacy'))->toBe("<p>plain suffix</p>\n");
	expect(fn() => $this->view->render('legacy'))->toThrow(RuntimeException::class, 'views/legacy.tmpl.php');
});
