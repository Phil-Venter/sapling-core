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

test('#[Config] fills a parameter; a default covers a missing key', function () {
	$container = new Container();
	$container->set(Config::class, new Config(['greeting' => 'hi']));
	$settings = $container->get(Settings::class);

	expect($settings->greeting)->toBe('hi');
	expect($settings->retries)->toBe(3);
});

test('#[Config] without a default throws on a missing key', function () {
	$container = new Container();
	$container->set(Config::class, new Config([]));

	expect(fn() => $container->get(Settings::class))->toThrow(LogicException::class, 'Missing config key: greeting');
});
