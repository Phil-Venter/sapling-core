<?php

use Sapling\App;
use Sapling\Cli\Console;
use Sapling\Http\Response;

const FIXTURES = __DIR__ . '/Fixtures';

// PHPUnit lowers error_reporting() during tests, which hides the warnings Sapling turns into crashes.
pest()->beforeEach(function () {
	error_reporting(E_ALL);
});

function scannedApp(bool $debug = false): App
{
	$app = new App(FIXTURES, $debug);
	$app->scan(FIXTURES . '/Scan', 'Sapling\\Tests\\Fixtures\\Scan\\');

	return $app;
}

function captureErrorLog(Closure $fn): array
{
	$log = tempnam(sys_get_temp_dir(), 'sapling-log');
	$previous = ini_set('error_log', $log);

	try {
		$result = $fn();
	} finally {
		ini_set('error_log', (string) $previous);
	}

	$lines = file($log, FILE_IGNORE_NEW_LINES);
	unlink($log);

	return [$result, $lines];
}

function sentBody(Response $response): string
{
	ob_start();
	$response->send();

	return ob_get_clean();
}

/**
 * Runs bin/console for the fixture app in a subprocess, with $ini as extra php.ini settings.
 *
 * @param list<string> $args
 * @param array<string, string> $ini
 * @return array{stdout: string, stderr: string, code: int}
 */
function runConsole(array $args = [], array $ini = []): array
{
	$settings = [];

	foreach (['xdebug.mode' => 'off', ...$ini] as $name => $value) {
		$settings[] = '-d';
		$settings[] = "{$name}={$value}";
	}

	$command = [PHP_BINARY, ...$settings, FIXTURES . '/app.php', ...$args];
	$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$code = proc_close($process);

	return ['stdout' => $stdout, 'stderr' => $stderr, 'code' => $code];
}

function httpServer(string $root = FIXTURES . '/public'): string
{
	static $running = [];

	if (array_key_exists($root, $running)) {
		return $running[$root];
	}

	$probe = stream_socket_server('tcp://127.0.0.1:0');
	$address = stream_socket_get_name($probe, false);
	fclose($probe);

	$log = (string) tempnam(sys_get_temp_dir(), 'sapling-server');
	$command = [
		PHP_BINARY,
		'-d',
		'xdebug.mode=off',
		'-d',
		'display_errors=0',
		'-S',
		$address,
		'-t',
		$root,
	];
	$process = proc_open(
		$command,
		[
			['pipe', 'r'],
			['file', '/dev/null', 'w'],
			['file', $log,        'w'],
		],
		$_pipes,
	);

	register_shutdown_function(function () use ($process, $log) {
		proc_terminate($process);
		unlink($log);
	});

	for ($attempt = 0; $attempt < 100; $attempt++) {
		if (str_contains((string) file_get_contents($log), 'started')) {
			return $running[$root] = $address;
		}

		usleep(50_000);
	}

	throw new RuntimeException("php -S did not start on {$address}");
}

function httpRequest(string $method, string $path, array $headers = [], string $body = ''): array
{
	$address = httpServer();
	$lines = [
		"{$method} {$path} HTTP/1.1",
		"Host: {$address}",
		'Connection: close',
		'Content-Length: ' . strlen($body),
	];

	foreach ($headers as $name => $value) {
		$lines[] = "{$name}: {$value}";
	}

	$socket = stream_socket_client("tcp://{$address}", timeout: 5);
	fwrite($socket, implode("\r\n", $lines) . "\r\n\r\n" . $body);
	$raw = stream_get_contents($socket);
	fclose($socket);

	[$head, $responseBody] = explode("\r\n\r\n", $raw, 2) + [1 => ''];
	$headerLines = explode("\r\n", $head);
	$statusLine = array_shift($headerLines);

	return ['status' => (int) explode(' ', $statusLine)[1], 'headers' => $headerLines, 'body' => $responseBody];
}

function headerValues(array $response, string $name): array
{
	$values = [];

	foreach ($response['headers'] as $line) {
		[$lineName, $value] = explode(':', $line, 2);

		if (strcasecmp(trim($lineName), $name) === 0) {
			$values[] = trim($value);
		}
	}

	return $values;
}

function runInApp(App $app, string ...$argv): array
{
	ob_start();
	$code = $app->runCommand(new Console(['bin/console', ...$argv]));

	return [$code, ob_get_clean()];
}
