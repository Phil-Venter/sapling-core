<?php

use Sapling\Config;
use Sapling\Container;
use Sapling\Tests\Fixtures\Container\Clock;
use Sapling\Tests\Fixtures\Container\Mailer;
use Sapling\Tests\Fixtures\Container\NeedsName;
use Sapling\Tests\Fixtures\Container\Settings;

test('class-typed constructor parameters are autowired and scalars use their default', function () {
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

test('#[Config] fills a parameter and a default covers a missing key, while a wrong type or a missing key throws', function () {
	$settings = function (array $config): Settings {
		$container = new Container();
		$container->set(Config::class, new Config($config));

		return $container->get(Settings::class);
	};
	$filled = $settings(['greeting' => 'hi']);

	expect($filled->greeting)->toBe('hi');
	expect($filled->retries)->toBe(3);
	expect(fn() => $settings(['greeting' => 'hi', 'retries' => '5']))
		->toThrow(TypeError::class, 'Argument #2 ($retries) must be of type int, string given');
	expect(fn() => $settings([]))->toThrow(LogicException::class, 'Missing config key: greeting');
});
