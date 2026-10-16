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

test('a slot or page that prints only 0 keeps it', function () {
	expect($this->view->render('zero'))->toBe('0');
});

test('named slots do not leak into the next page', function () {
	$this->view->render('page', ['name' => 'Ann']);

	expect($this->view->render('untitled'))->toContain('<title>Default</title>');
});

test('an error inside a template closes its buffers and leaves no state behind', function () {
	$level = ob_get_level();

	expect(fn() => $this->view->render('broken'))->toThrow(RuntimeException::class, 'template failed');
	expect(ob_get_level())->toBe($level);
	expect($this->view->render('untitled'))->toContain('<title>Default</title>');
});

test('an unclosed component, a stray end() or a stray slot() throws instead of garbling the page', function () {
	$level = ob_get_level();

	expect(fn() => $this->view->render('unclosed'))
		->toThrow(LogicException::class, 'Component _card in unclosed has no end()');
	expect(fn() => $this->view->render('stray-end'))
		->toThrow(LogicException::class, 'end() without an open slot() or component()');
	expect(fn() => $this->view->render('stray-slot'))->toThrow(LogicException::class, 'slot() outside a component()');
	expect(ob_get_level())->toBe($level);
	expect($this->view->render('plain', ['name' => 'Ann']))->toBe("<p>Ann on Sapling</p>\n");
});

test('a partial that closes what its page opened throws instead of garbling the page', function () {
	expect(fn() => $this->view->render('closes-parent'))
		->toThrow(LogicException::class, "end() in _closes closes a component or slot it didn't open");
});

test('slot() with a value passes it as it is, such as a list', function () {
	expect($this->view->render('scripts-page'))->toBe('<script src="/a.js"></script><script src="/b.js"></script>');
});

test('a component gets its data, and what was printed before end() as its default slot', function () {
	expect($this->view->render('card-page'))->toBe("<article><h2>Notes</h2><p>Inside</p>\n</article>\n");
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
