<?php

use Sapling\App;
use Sapling\Config;
use Sapling\Http\Middleware\Csrf;
use Sapling\Http\Middleware\SecurityHeaders;

$config = Config::load(
	configFile: __DIR__ . '/config.php',
	envFile: __DIR__ . '/.env',
);

$app = App::boot(__DIR__, $config);
$app->scan(__DIR__ . '/app', 'App');

$app->middleware(SecurityHeaders::class);
$app->middleware(Csrf::class);

return $app;
