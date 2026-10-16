<?php

use Sapling\Template;
use Sapling\Tests\Fixtures\Priority;

beforeEach(function () {
	$this->view = new Template(FIXTURES . '/views');
	$this->view->share('site', 'Sapling');
});

test('a view with a layout renders its sections and content', function () {
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

test('layouts can nest', function () {
	expect($this->view->render('child'))
		->toContain('<title>Child</title>')
		->toMatch('#<main>\s*<section>\s*<p>child body</p>\s*</section>\s*</main>#');
});

test('a section or page that prints only 0 keeps it', function () {
	expect($this->view->render('zero'))->toBe('0');
});

test('sections do not leak into the next page, even from a page without a layout', function (string $first) {
	$this->view->render($first, ['name' => 'Ann']);

	expect($this->view->render('untitled'))->toContain('<title>Default</title>');
})->with(['page', 'titled-fragment']);

test('an error inside a template closes its buffers and leaves no state behind', function () {
	$level = ob_get_level();

	expect(fn() => $this->view->render('broken'))->toThrow(RuntimeException::class, 'template failed');
	expect(ob_get_level())->toBe($level);
	expect($this->view->render('untitled'))->toContain('<title>Default</title>');
});

test('section() keeps a string, number or backed enum as text', function () {
	$this->view->section('text', 'Notes');
	$this->view->section('number', 2.5);
	$this->view->section('enum', Priority::High);

	expect([$this->view->yield('text'), $this->view->yield('number'), $this->view->yield('enum')])->toBe([
		'Notes',
		'2.5',
		'1',
	]);
});

test('a section without end(), or an end() without a section, throws instead of garbling the page', function () {
	$level = ob_get_level();

	expect(fn() => $this->view->render('unclosed'))
		->toThrow(LogicException::class, 'Section aside in unclosed has no end()');
	expect(fn() => $this->view->render('stray-end'))
		->toThrow(LogicException::class, 'end() without an open section() or component()');
	expect(fn() => $this->view->render('unclosed-component'))
		->toThrow(LogicException::class, 'Component _card in unclosed-component has no end()');
	expect(ob_get_level())->toBe($level);
	expect($this->view->render('plain', ['name' => 'Ann']))->toBe("<p>Ann on Sapling</p>\n");
});

test('a component gets its data, and what was printed before end() as $slot', function () {
	expect($this->view->render('card-page'))->toBe("<article><h2>Notes</h2><p>Inside</p>\n</article>\n");
});

test('render() inside a template throws, since it would wipe the page, and points at include()', function () {
	expect(fn() => $this->view->render('nested-render'))
		->toThrow(LogicException::class, "render() inside a template, use include('item')");
	expect($this->view->render('plain', ['name' => 'Ann']))->toBe("<p>Ann on Sapling</p>\n");
});

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

test('a partial that calls layout() leaves the page without one', function () {
	expect($this->view->render('includes-layout'))->toBe("plain page\n");
});
