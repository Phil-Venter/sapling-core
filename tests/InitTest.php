<?php

use Sapling\Http\Request;

beforeEach(function () {
	$this->dir = sys_get_temp_dir() . '/sapling-init-' . bin2hex(random_bytes(4));
	mkdir($this->dir);
	file_put_contents("{$this->dir}/composer.json", "{\n    \"require\": {},\n    \"extra\": {}\n}\n");
});

afterEach(function () {
	exec('rm -rf ' . escapeshellarg($this->dir));
});

function runInit(string $dir): string
{
	$command = [PHP_BINARY, '-d', 'xdebug.mode=off', dirname(__DIR__) . '/bin/sapling', 'init'];
	$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
	$output = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($process);

	return $output;
}

test('init adds the four files, an app/ folder and a dev .env, and loads App\ from app/', function () {
	$output = runInit($this->dir);
	$composer = file_get_contents("{$this->dir}/composer.json");

	expect($output)->toContain('created  bootstrap.php')->toContain('created  app/')->toContain('created  .env');
	expect(file_get_contents("{$this->dir}/.gitignore"))->toBe(".env\n");
	expect(file_get_contents("{$this->dir}/.env"))->toMatch('#^APP_ENV=dev\nAPP_KEY=[A-Za-z0-9+/]{43}=\n$#');
	expect(json_decode($composer, true)['autoload']['psr-4']['App\\'])->toBe('app/');
	expect($composer)->toContain('"extra": {}');
	expect(is_executable("{$this->dir}/bin/console"))->toBeTrue();
});

test('running init again changes nothing', function () {
	runInit($this->dir);
	$env = file_get_contents("{$this->dir}/.env");

	$output = runInit($this->dir);

	expect($output)->toContain('skipped  bootstrap.php')->not->toContain('created');
	expect(file_get_contents("{$this->dir}/.env"))->toBe($env);
});

test('the new app boots, and a class added to app/ is its first page', function () {
	runInit($this->dir);
	$dir = $this->dir;
	file_put_contents("{$dir}/app/FirstPage.php", <<<'PHP'
		<?php

		namespace App;

		use Sapling\Attributes\Route;
		use Sapling\Http\Response;

		#[Route('GET', '/')]
		final class FirstPage
		{
			public function __invoke(): Response
			{
				return Response::text('It works');
			}
		}
		PHP);
	spl_autoload_register(function (string $class) use ($dir) {
		$file = "{$dir}/app/" . str_replace(['App\\', '\\'], ['', '/'], $class) . '.php';

		if (is_file($file)) {
			require $file;
		}
	});

	$app = require "{$this->dir}/bootstrap.php";

	expect($app->handle(new Request('GET', '/'))->body)->toBe('It works');
	expect($app->handle(new Request('GET', '/nope'))->status)->toBe(404);
});

test('the new app answers a missing page with a 404 through its own public index', function () {
	runInit($this->dir);
	mkdir("{$this->dir}/vendor");
	file_put_contents(
		"{$this->dir}/vendor/autoload.php",
		"<?php\n\nrequire '" . dirname(__DIR__) . "/vendor/autoload.php';\n",
	);
	$address = httpServer("{$this->dir}/public");
	$context = stream_context_create(['http' => ['ignore_errors' => true]]);

	$body = file_get_contents("http://{$address}/nope", context: $context);
	$status = http_get_last_response_headers()[0] ?? '';

	expect($status)->toContain(' 404 ');
	expect($body)->toBe('Not Found');
});
