<?php

use Sapling\Config;
use Sapling\Container;
use Sapling\Tests\Fixtures\Container\Chicken;
use Sapling\Tests\Fixtures\Container\Clock;
use Sapling\Tests\Fixtures\Container\CurrentUser;
use Sapling\Tests\Fixtures\Container\Egg;
use Sapling\Tests\Fixtures\Container\Mailer;
use Sapling\Tests\Fixtures\Container\NeedsName;
use Sapling\Tests\Fixtures\Container\Profile;
use Sapling\Tests\Fixtures\Container\Settings;

test('constructor parameters are autowired by type', function () {
	$mailer = new Container()->get(Mailer::class);

	expect($mailer->clock)->toBeInstanceOf(Clock::class);
	expect($mailer->from)->toBe('noreply@example.com');
});

test('everything is shared, one instance per class', function () {
	$container = new Container();

	expect($container->get(Mailer::class))->toBe($container->get(Mailer::class));
	expect($container->get(Mailer::class)->clock)->toBe($container->get(Clock::class));
});

test('a Closure factory runs once, on first get()', function () {
	$container = new Container();
	$calls = 0;
	$container->set(Clock::class, function () use (&$calls) {
		$calls++;

		return new Clock();
	});

	expect($calls)->toBe(0);
	$first = $container->get(Clock::class);
	$second = $container->get(Clock::class);

	expect($calls)->toBe(1);
	expect($first)->toBe($second);
});

test('an unresolvable scalar parameter names the class and the parameter', function () {
	expect(fn() => new Container()->get(NeedsName::class))
		->toThrow(LogicException::class, 'Cannot autowire $name in ' . NeedsName::class);
});

test('a circular dependency names the loop', function () {
	$loop = Chicken::class . ' -> ' . Egg::class . ' -> ' . Chicken::class;

	expect(fn() => new Container()->get(Chicken::class))
		->toThrow(LogicException::class, "Circular dependency: {$loop}");
});

test('a factory that needs its own class names the loop', function () {
	$container = new Container();
	$container->set(Egg::class, fn(Container $container) => $container->get(Chicken::class));
	$loop = Egg::class . ' -> ' . Chicken::class . ' -> ' . Egg::class;

	expect(fn() => $container->get(Egg::class))->toThrow(LogicException::class, "Circular dependency: {$loop}");
});

test('#[Config] fills a parameter from the config, or from its default', function () {
	$container = new Container();
	$container->set(Config::class, new Config(['greeting' => 'hi']));

	$settings = $container->get(Settings::class);

	expect($settings->greeting)->toBe('hi');
	expect($settings->retries)->toBe(3);
});

test('#[Config] refuses a setting of the wrong type', function () {
	$container = new Container();
	$container->set(Config::class, new Config(['greeting' => 'hi', 'retries' => '5']));

	expect(fn() => $container->get(Settings::class))
		->toThrow(TypeError::class, 'Argument #2 ($retries) must be of type int, string given');
});

test('#[Config] refuses a missing setting that has no default', function () {
	$container = new Container();
	$container->set(Config::class, new Config([]));

	expect(fn() => $container->get(Settings::class))->toThrow(LogicException::class, 'Missing config key: greeting');
});

test('set() refuses to replace a class after handing it out', function () {
	$container = new Container();
	$container->set(CurrentUser::class, new CurrentUser('guest'));
	$container->get(Profile::class);

	expect(fn() => $container->set(CurrentUser::class, new CurrentUser('ann')))
		->toThrow(LogicException::class, 'Cannot replace ' . CurrentUser::class . ' after handing it out');
});

test('set() replaces a class that has not been handed out', function () {
	$container = new Container();
	$container->set(CurrentUser::class, new CurrentUser('guest'));

	$container->set(CurrentUser::class, new CurrentUser('ann'));

	expect($container->get(CurrentUser::class)->name)->toBe('ann');
});
