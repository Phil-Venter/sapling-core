<?php

return [
	'debug' => ($env['APP_ENV'] ?? 'production') === 'dev',
	'app_key' => $env['APP_KEY'] ?? '',
	'timezone' => 'UTC',
];
