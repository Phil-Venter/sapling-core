<?php

use Sapling\Config;

const CONFIG_FIXTURES = FIXTURES . '/config';

beforeEach(function () {
	$this->env = $_ENV;
	$this->server = $_SERVER;
	putenv('SAPLING_TEST_SERVER_ONLY=from the server');
	$_ENV['SAPLING_TEST_SERVER_ONLY'] = 'from the server';
});

afterEach(function () {
	putenv('SAPLING_TEST_SERVER_ONLY');
	$_ENV = $this->env;
	$_SERVER = $this->server;
});

test('load() passes .env values to config.php as strings', function () {
	$config = Config::load(CONFIG_FIXTURES . '/config.php', CONFIG_FIXTURES . '/test.env');

	expect($config->get('env'))->toMatchArray([
		'APP_ENV' => 'dev',
		// @mago-expect lint:no-literal-password
		'APP_PASSWORD' => 'quoted secret',
		'SINGLE' => 'single quoted',
		'EMPTY' => '',
		'SPACED' => 'padded value',
		'EQUALS' => 'a=b=c',
		'HASH' => 'not#a#comment',
		'INLINE' => 'value',
		'QUOTED_HASH' => 'keeps # this',
		'ONLY_COMMENT' => '',
		'MISMATCHED' => '"half',
	]);
	expect($config->get('included'))->toBe('dev');
});

test('real environment variables win over the env file', function () {
	putenv('APP_ENV=production');
	$config = Config::load(CONFIG_FIXTURES . '/config.php', CONFIG_FIXTURES . '/test.env');
	putenv('APP_ENV');

	expect($config->get('env')['APP_ENV'])->toBe('production');
	expect($config->get('env')['SAPLING_TEST_SERVER_ONLY'])->toBe('from the server');
	expect($config->get('env')['SINGLE'])->toBe('single quoted');
});

test('the env file is optional', function () {
	$config = Config::load(CONFIG_FIXTURES . '/config.php', CONFIG_FIXTURES . '/missing.env');

	expect($config->get('env')['SAPLING_TEST_SERVER_ONLY'])->toBe('from the server');
});

test('env file values never leak into getenv(), $_ENV or $_SERVER', function () {
	Config::load(CONFIG_FIXTURES . '/config.php', CONFIG_FIXTURES . '/test.env');

	expect(getenv('APP_PASSWORD'))->toBeFalse();
	expect($_ENV)->not->toHaveKey('APP_PASSWORD');
	expect($_SERVER)->not->toHaveKey('APP_PASSWORD');
	expect(getenv('SAPLING_TEST_SERVER_ONLY'))->toBe('from the server');
});

test('get() tells a null setting from a missing one', function () {
	$config = new Config(['debug' => true, 'nothing' => null]);

	expect($config->get('debug', false))->toBeTrue();
	expect($config->get('nothing', 'fallback'))->toBeNull();
	expect($config->get('db_file', null))->toBeNull();
	expect(fn() => $config->get('db_file'))->toThrow(LogicException::class, 'Missing config key: db_file');
});
