<?php

use Sapling\App;
use Sapling\Cli\Console;
use Sapling\Config;
use Sapling\Crypto;
use Sapling\Http\HttpException;
use Sapling\Http\Middleware\Cors;
use Sapling\Http\Middleware\Csrf;
use Sapling\Http\Middleware\SecurityHeaders;
use Sapling\Http\Request;
use Sapling\Http\Response;
use Sapling\Http\Session;
use Sapling\Tests\Fixtures\Broken\DuplicateGreet;
use Sapling\Tests\Fixtures\JsonErrors;
use Sapling\Tests\Fixtures\Middleware\Deny;
use Sapling\Tests\Fixtures\Middleware\GlobalOne;
use Sapling\Tests\Fixtures\Middleware\GlobalTwo;
use Sapling\Tests\Fixtures\Middleware\Stamp;
use Sapling\Tests\Fixtures\NoteForm;
use Sapling\Tests\Fixtures\Scan\Commands\Greet;
use Sapling\Tests\Fixtures\Trace;

const BOOT = FIXTURES . '/Boot';

function bootConfig(): Config
{
	return new Config(['debug' => true, 'session_lifetime' => 3600]);
}

describe('boot()', function () {
	afterEach(function () {
		if (session_status() !== PHP_SESSION_ACTIVE) {
			return;
		}

		session_destroy();
	});

	test('takes debug from the config, and a scanned action gets Config and Template', function () {
		$app = App::boot(BOOT, bootConfig());
		$app->scan(BOOT . '/App', 'Sapling\Tests\Fixtures\Boot\App');

		expect($app->debug)->toBeTrue();
		expect($app->handle(new Request('GET', '/hello'))->body)->toBe("<p>hello from dev</p>\n");
	});

	test('sets the default timezone from the config, and leaves it alone without one', function () {
		$before = date_default_timezone_get();

		try {
			App::boot(BOOT, new Config(['timezone' => 'Africa/Johannesburg']));
			$set = date_default_timezone_get();

			App::boot(BOOT, new Config([]));
			$kept = date_default_timezone_get();
		} finally {
			date_default_timezone_set($before);
		}

		expect([$set, $kept])->toBe(['Africa/Johannesburg', 'Africa/Johannesburg']);
	});

	test('an unknown timezone stops the boot', function () {
		expect(fn() => App::boot(BOOT, new Config(['timezone' => 'Mars/Olympus'])))
			->toThrow(Exception::class, 'Mars/Olympus');
	});

	test('gives Session the session_lifetime from the config', function () {
		App::boot(BOOT, bootConfig())->container->get(Session::class)->get('anything');

		expect(session_get_cookie_params()['lifetime'])->toBe(3600);
		expect(ini_get('session.gc_maxlifetime'))->toBe('3600');
	});

	test('gives Crypto the app_key and Cors the cors_origins from the config', function () {
		$key = base64_encode(random_bytes(32));
		$app = App::boot(BOOT, new Config(['app_key' => $key, 'cors_origins' => ['https://app.example']]));
		$app->scan(BOOT . '/App', 'Sapling\Tests\Fixtures\Boot\App');
		$app->middleware(Cors::class);

		$stored = $app->container->get(Crypto::class)->encrypt('secret', 'test');
		$response = $app->handle(new Request('GET', '/hello', headers: ['Origin' => 'https://app.example']));

		expect(new Crypto($key)->decrypt($stored, 'test'))->toBe('secret');
		expect($response->headers['Access-Control-Allow-Origin'])->toBe('https://app.example');
	});

	test('gives SecurityHeaders the content_policy from the config', function () {
		$app = App::boot(BOOT, new Config(['content_policy' => ['img-src' => "'self'"]]));
		$app->scan(BOOT . '/App', 'Sapling\Tests\Fixtures\Boot\App');
		$app->middleware(SecurityHeaders::class);

		$response = $app->handle(new Request('GET', '/hello'));

		expect($response->headers['Content-Security-Policy'])->toContain("img-src 'self';");
	});

	test('refuses a string where a setting must be true or false, since "false" would read as on', function (string $key) {
		expect(
			fn() => App::boot(BOOT, new Config([
				$key => 'false',
				'cors_origins' => ['https://app.example'],
			]))->container->get(Cors::class),
		)
			->toThrow(TypeError::class, 'must be of type bool, string given');
	})->with(['debug', 'cors_credentials']);

	test('Csrf trusts the sites in cors_origins, but never *', function () {
		$app = App::boot(BOOT, new Config(['cors_origins' => ['https://front.example', '*']]));
		$app->scan(BOOT . '/App', 'Sapling\Tests\Fixtures\Boot\App');
		$app->middleware(Csrf::class);
		$post = fn(string $origin) => $app->handle(new Request('POST', '/hello', headers: [
			'Sec-Fetch-Site' => 'cross-site',
			'Origin' => $origin,
		]))->status;

		expect($post('https://front.example'))->toBe(405);
		expect($post('https://evil.example'))->toBe(403);
	});

	test('needs no config keys: debug is off and Session uses php.ini', function () {
		$app = App::boot(BOOT, new Config([]));
		$app->scan(BOOT . '/App', 'Sapling\Tests\Fixtures\Boot\App');

		expect($app->debug)->toBeFalse();
		expect($app->handle(new Request('GET', '/hello'))->body)->toBe("<p>hello from production</p>\n");
		expect($app->container->get(Session::class))->toBeInstanceOf(Session::class);
	});
});

describe('registering in code', function () {
	test('scan() takes the namespace with or without its trailing backslash', function (string $namespace) {
		$app = new App(FIXTURES);
		$app->scan(FIXTURES . '/Scan', $namespace);

		expect($app->handle(new Request('GET', '/notes'))->status)->toBe(200);
	})->with(['without' => 'Sapling\Tests\Fixtures\Scan', 'with' => 'Sapling\Tests\Fixtures\Scan\\']);

	test('scan() only reads files that start with a capital and have no dot', function () {
		$app = new App(FIXTURES);
		$app->scan(FIXTURES . '/Scan', 'Sapling\\Tests\\Fixtures\\Scan');

		expect($app->handle(new Request('GET', '/notes'))->status)->toBe(200);
		expect(function_exists('scan_fixture_helper'))->toBeFalse();
		expect(function_exists('scan_fixture_shared'))->toBeFalse();
	});

	test('add() refuses a class with no #[Route] or #[Command], which scan() would skip', function () {
		$app = new App(FIXTURES);

		expect(fn() => $app->add(Sapling\Tests\Fixtures\Container\Clock::class))
			->toThrow(LogicException::class, 'Clock has no #[Route] or #[Command] to add');
	});

	test('scan() takes its folder with or without a trailing slash', function () {
		$app = new App(FIXTURES);
		$app->scan(FIXTURES . '/Scan/', 'Sapling\\Tests\\Fixtures\\Scan\\');

		expect($app->handle(new Request('GET', '/'))->status)->toBe(200);
	});

	test('routes, their middleware and commands can be closures registered in code', function () {
		$app = new App(FIXTURES);
		$app->router->add(
			'get',
			'/hello/:name',
			fn(Request $request) => Response::text("Hello {$request->param('name')->string()}"),
			[
				fn(Request $request, Closure $next) => $next($request)->withHeader('X-Route', 'yes'),
			],
		);
		$app->commands->add('greet', function (Console $console): int {
			$console->line('Hello');

			return 0;
		});

		$response = $app->handle(new Request('GET', '/hello/Ann'));

		expect([$response->body, $response->headers['X-Route']])->toBe(['Hello Ann', 'yes']);
		expect(runInApp($app, 'greet'))->toBe([0, "Hello\n"]);
		expect(runInApp($app, 'routes:list'))->toBe([0, "GET  /hello/:name  Closure\n"]);
	});

	test('a closure can still clash with a route or command name', function () {
		$app = new App(FIXTURES);
		$app->router->add('GET', '/hello/:name', fn() => Response::text('one'));
		$app->commands->add('greet', fn() => 0);

		expect(fn() => $app->router->add('GET', '/hello/:who', fn() => Response::text('two')))
			->toThrow(LogicException::class, 'Duplicate route GET /hello/:who: Closure and Closure');
		expect(fn() => $app->commands->add('greet', fn() => 0))
			->toThrow(LogicException::class, 'Duplicate command greet: Closure and Closure');
	});

	test('a class can have more than one #[Route]', function () {
		$app = new App(FIXTURES);
		$app->add(NoteForm::class);

		$new = $app->handle(new Request('GET', '/notes/form'));
		$edit = $app->handle(new Request('GET', '/notes/form/7'));

		expect([$new->body, $edit->body])->toBe(['form for a new note', 'form for 7']);
	});
});

describe('middleware', function () {
	test('global middleware runs first, then the action middleware in attribute order, then the action', function () {
		$app = scannedApp();
		$app->middleware(GlobalOne::class);
		$app->middleware(GlobalTwo::class);

		$response = $app->handle(new Request('GET', '/guarded'));

		expect($response->body)->toBe('guarded');
		expect($app->container->get(Trace::class)->events)->toBe([
			'GlobalOne in',
			'GlobalTwo in',
			'First in',
			'Second in',
			'action',
			'Second out',
			'First out',
			'GlobalTwo out',
			'GlobalOne out',
		]);
	});

	test('action middleware can return early', function () {
		$app = scannedApp();
		$app->middleware(GlobalOne::class);

		$response = $app->handle(new Request('GET', '/blocked'));

		expect($response->status)->toBe(403);
		expect($response->body)->toBe('denied');
		expect($app->container->get(Trace::class)->events)->toBe(['GlobalOne in', 'Deny', 'GlobalOne out']);
	});

	test('global middleware can return early, before routing', function () {
		$app = scannedApp();
		$app->middleware(Deny::class);
		$app->middleware(GlobalOne::class);

		$response = $app->handle(new Request('GET', '/nowhere'));

		expect($response->status)->toBe(403);
		expect($app->container->get(Trace::class)->events)->toBe(['Deny']);
	});

	test('404 and 405 reach global middleware as responses, with their headers', function () {
		$app = scannedApp();
		$app->middleware(Stamp::class);

		$notFound = $app->handle(new Request('GET', '/nowhere'));
		$notAllowed = $app->handle(new Request('PATCH', '/notes/7'));

		expect([$notFound->status, $notFound->body, $notFound->headers['X-Stamp']])->toBe([
			404,
			'Not Found',
			'saw 404',
		]);
		expect([$notAllowed->status, $notAllowed->headers['X-Stamp']])->toBe([405, 'saw 405']);
		expect($notAllowed->headers['Allow'])->toBe('DELETE, GET, HEAD');
	});

	test('an HttpException thrown by middleware reaches the middleware outside it as a response', function () {
		$app = scannedApp();
		$app->middleware(Stamp::class);
		$app->middleware(fn() => throw new Sapling\Http\HttpException(429, 'Slow down'));

		$response = $app->handle(new Request('GET', '/notes'));

		expect([$response->status, $response->body, $response->headers['X-Stamp']])->toBe([
			429,
			'Slow down',
			'saw 429',
		]);
	});

	test('middleware can be a class, an object or a closure', function () {
		$app = scannedApp();
		$app->middleware(GlobalOne::class);
		$app->middleware(new SecurityHeaders(['img-src' => "'self'"]));
		$app->middleware(fn(Request $request, Closure $next): Response => $next($request)->withHeader(
			'X-Closure',
			'yes',
		), prefix: '/notes');

		$headers = $app->handle(new Request('GET', '/notes'))->headers;

		expect([$headers['X-Global-One'], $headers['X-Closure']])->toBe(['yes', 'yes']);
		expect($headers['Content-Security-Policy'])->toContain("img-src 'self';");
	});

	test('a prefix runs global middleware for that path and below only', function (
		string $prefix,
		string $path,
		bool $runs,
	) {
		$app = scannedApp();
		$app->middleware(GlobalOne::class, prefix: $prefix);

		$headers = $app->handle(new Request('GET', $path))->headers;

		expect(array_key_exists('X-Global-One', $headers))->toBe($runs);
	})->with([
		'the path itself' => ['/notes', '/notes', true],
		'below it, trailing slash in the prefix' => ['/notes/', '/notes/7', true],
		'no leading slash in the prefix' => ['notes', '/notes/7', true],
		'a longer name' => ['/note', '/notes', false],
		'elsewhere' => ['/notes', '/guarded', false],
	]);
});

describe('errors', function () {
	test('HttpException(403) becomes a 403 and is not logged', function () {
		[$response, $log] = captureErrorLog(fn() => scannedApp()->handle(new Request('GET', '/forbidden')));

		expect($response->status)->toBe(403);
		expect($response->body)->toBe('Forbidden');
		expect($response->headers['Content-Type'])->toBe('text/plain; charset=utf-8');
		expect($log)->toBe([]);
	});

	test('any other exception logs one line with method and path, then returns a plain 500', function () {
		[$response, $log] = captureErrorLog(fn() => scannedApp()->handle(new Request('GET', '/fails')));

		expect($response->status)->toBe(500);
		expect($response->body)->toBe('Internal Server Error');
		expect($log)->toHaveCount(1);
		expect($log[0])->toContain('GET /fails failed: RuntimeException: boom <script>');
		expect($log[0])->toContain('Fails.php:');
	});

	test('debug mode shows an escaped trace', function () {
		[$response] = captureErrorLog(fn() => scannedApp(debug: true)->handle(new Request('GET', '/fails')));

		expect($response->status)->toBe(500);
		expect($response->headers['Content-Type'])->toBe('text/html; charset=utf-8');
		expect($response->body)->toContain('<h1>RuntimeException</h1>');
		expect($response->body)->toContain('boom &lt;script&gt;');
		expect($response->body)->toContain('Fails.php:');
		expect($response->body)->toContain('<span class="here">');
		expect($response->body)->toMatch('#<summary>\d+ frames</summary>#');
		expect(explode('<details', $response->body)[0])->toContain('Fails-&gt;__invoke()');
		expect($response->body)->not->toContain('<script>');
	});

	test('handle() turns warnings into a 500, so tests see what production sees', function () {
		[$response, $log] = captureErrorLog(fn() => scannedApp()->handle(new Request('GET', '/warns')));

		expect($response->status)->toBe(500);
		expect($log[0])->toContain('GET /warns failed: ErrorException: Undefined array key 0');
	});

	test('handle() puts the previous error handler back', function () {
		$current = function () {
			$handler = set_error_handler(fn() => false);
			restore_error_handler();

			return $handler;
		};
		$before = $current();

		captureErrorLog(fn() => scannedApp()->handle(new Request('GET', '/warns')));
		scannedApp()->handle(new Request('GET', '/notes'));

		expect($current())->toBe($before);
	});

	test('an HttpException with a 5xx status is logged by its status', function () {
		[$unavailable, $log] = captureErrorLog(fn() => scannedApp()->handle(new Request('GET', '/unavailable')));

		expect([$unavailable->status, $unavailable->body])->toBe([503, 'Down for maintenance']);
		expect($log)->toHaveCount(1);
		expect($log[0])->toContain('GET /unavailable failed: 503');
	});

	test('deprecations are not turned into exceptions', function () {
		$response = httpRequest('GET', '/deprecated');

		expect([$response['status'], $response['body']])->toBe([200, 'still works']);
	});
	test('a crash inside Sapling or a package also logs the line of your code that led there', function () {
		$app = new App(FIXTURES);
		$app->router->add('GET', '/report', require FIXTURES . '/missing-download.php');

		[, $log] = captureErrorLog(fn() => $app->handle(new Request('GET', '/report')));

		expect($log[0])
			->toContain('File not found: /no/such/report.pdf in ')
			->toContain('src/Http/Response.php:')
			->toEndWith(' from ' . FIXTURES . '/missing-download.php:5');
	});
});

describe('onError()', function () {
	test('answers every HttpException with the request, and middleware sees its response', function () {
		$app = scannedApp();
		$app->middleware(Stamp::class);
		$app->onError(fn(HttpException $e, Request $request): Response => Response::text(
			"{$e->status} at {$request->path}: {$e->getMessage()}",
			$e->status,
		));

		$notFound = $app->handle(new Request('GET', '/nowhere'));
		$forbidden = $app->handle(new Request('GET', '/forbidden'));

		expect([$notFound->body, $notFound->headers['X-Stamp']])->toBe(['404 at /nowhere: Not Found', 'saw 404']);
		expect([$forbidden->body, $forbidden->headers['X-Stamp']])->toBe(['403 at /forbidden: Forbidden', 'saw 403']);
	});

	test('adds the exception\'s headers to the handler\'s response, without replacing the handler\'s own', function () {
		$app = new App(FIXTURES);
		$app->router->add('GET', '/busy', fn() => throw new HttpException(503, '', [
			'Retry-After' => '60',
			'Content-Type' => 'application/json',
		]));
		$app->onError(fn(HttpException $e): Response => Response::html('<h1>Busy</h1>', $e->status));

		$response = $app->handle(new Request('GET', '/busy'));

		expect($response->headers)->toMatchArray([
			'Content-Type' => 'text/html; charset=utf-8',
			'Retry-After' => '60',
		]);
	});

	test('takes a class name, built by the container when an error happens', function () {
		$app = scannedApp();
		$app->onError(JsonErrors::class);

		$ok = $app->handle(new Request('GET', '/notes'));
		$trace = $app->container->get(Trace::class);
		expect($trace->events)->toBe([]);

		$response = $app->handle(new Request('PATCH', '/notes/7'));

		expect([$ok->status, $response->status, $response->body])->toBe([200, 405, '{"error":"Method Not Allowed"}']);
		expect($response->headers)->toMatchArray([
			'Content-Type' => 'application/json',
			'Allow' => 'DELETE, GET, HEAD',
		]);
		expect($trace->events)->toBe(['PATCH /notes/7']);
	});
});

describe('testing', function () {
	test('a test can sign in by setting the session before handle()', function () {
		$app = new App(FIXTURES);
		$app->router->add('GET', '/me', function () use ($app): Response {
			$user = $app->container->get(Session::class)->get('user_id')->int();

			return Response::text("user {$user}");
		});

		$session = $app->container->get(Session::class);
		$session->set('user_id', 7);

		try {
			expect($app->handle(new Request('GET', '/me'))->body)->toBe('user 7');
		} finally {
			$session->destroy();
		}
	});
});

describe('debug page', function () {
	test('an app closure is shown by its relative file, whatever the base path ends in', function (string $base) {
		$app = new App($base, debug: true);
		$app->router->add('GET', '/closure', require FIXTURES . '/closure-route.php');

		[$response] = captureErrorLog(fn() => $app->handle(new Request('GET', '/closure')));

		expect(explode('<details', $response->body)[0])->toContain('{closure:closure-route.php:3}()');
	})->with(['without' => FIXTURES, 'with' => FIXTURES . '/']);
});

describe('commands', function () {
	test('a warning in a command prints the error once, with the full trace, and exits 1', function () {
		$result = runConsole(['crash']);

		expect($result['code'])->toBe(1);
		expect($result['stdout'])->toBe('');
		expect(substr_count($result['stderr'], 'Undefined array key 0'))->toBe(1);
		expect($result['stderr'])->toContain('Stack trace:');
	});

	test('a crashed command is also logged when error_log is set', function () {
		$log = (string) tempnam(sys_get_temp_dir(), 'sapling-log');

		try {
			$result = runConsole(['crash'], ['error_log' => $log]);

			expect((string) file_get_contents($log))
				->toContain('command crash failed: ErrorException: Undefined array key 0');
			expect($result['stderr'])->toContain('Stack trace:');
		} finally {
			unlink($log);
		}
	});

	test('a usage mistake, such as an option without =, prints only its message', function () {
		$result = runConsole(['greet', '--times', '2']);

		expect($result['code'])->toBe(1);
		expect($result['stderr'])->toBe("--times needs a value, written --times=value\n");
	});

	test('an InvalidArgumentException from a bug is still a crash, with its trace and a log line', function () {
		$log = (string) tempnam(sys_get_temp_dir(), 'sapling-log');

		try {
			$result = runConsole(['misconfigured'], ['error_log' => $log]);

			expect($result['code'])->toBe(1);
			expect($result['stderr'])->toContain('Stack trace:');
			expect((string) file_get_contents($log))
				->toContain('command misconfigured failed: InvalidArgumentException: app_key must be 32 bytes');
		} finally {
			unlink($log);
		}
	});

	test('the same command name on two classes throws and names both', function () {
		$app = scannedApp();

		expect(fn() => $app->add(DuplicateGreet::class))
			->toThrow(
				LogicException::class,
				'Duplicate command greet: ' . Greet::class . ' and ' . DuplicateGreet::class,
			);
	});

	test('run() from the CLI runs the command and exits with its code', function () {
		$greet = runConsole(['greet', 'Ann', '--shout']);
		$fail = runConsole(['fail']);

		expect($greet)->toBe(['stdout' => "Hello Ann!\n", 'stderr' => '', 'code' => 0]);
		expect($fail)->toBe(['stdout' => '', 'stderr' => "failing on purpose\n", 'code' => 3]);
	});

	test('an unknown command lists every command and exits 1', function () {
		$result = runConsole(['nope']);

		expect($result['code'])->toBe(1);
		expect($result['stderr'])->toBe("Unknown command: nope\n");
		expect($result['stdout'])
			->toBe("Commands:\n  commands:list  List every command\n\n  crash          Trigger a warning\n  fail           Exit with code 3\n  greet          Say hello to arg 0\n  misconfigured  Throw an InvalidArgumentException, as a bug does\n\n  routes:list    List every route and its class\n");
	});

	test('no command at all runs commands:list and exits 0', function () {
		$result = runConsole();

		expect($result['code'])->toBe(0);
		expect($result['stderr'])->toBe('');
		expect($result['stdout'])->toStartWith("Commands:\n");
	});
});
