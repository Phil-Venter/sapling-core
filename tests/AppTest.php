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
use Sapling\Tests\Fixtures\Container\Clock;
use Sapling\Tests\Fixtures\Container\CurrentUser;
use Sapling\Tests\Fixtures\Container\Profile;
use Sapling\Tests\Fixtures\JsonErrors;
use Sapling\Tests\Fixtures\Middleware\Deny;
use Sapling\Tests\Fixtures\Middleware\GlobalOne;
use Sapling\Tests\Fixtures\Middleware\GlobalTwo;
use Sapling\Tests\Fixtures\Middleware\Stamp;
use Sapling\Tests\Fixtures\NoteForm;
use Sapling\Tests\Fixtures\Scan\Commands\Greet;
use Sapling\Tests\Fixtures\Trace;

const BOOT = FIXTURES . '/Boot';

describe('boot()', function () {
	afterEach(function () {
		if (session_status() !== PHP_SESSION_ACTIVE) {
			return;
		}

		session_destroy();
	});

	test('takes debug from the config', function (array $settings, bool $debug, string $body) {
		$app = App::boot(BOOT, new Config($settings));
		$app->scan(BOOT . '/App', 'Sapling\Tests\Fixtures\Boot\App');

		expect($app->debug)->toBe($debug);
		expect($app->handle(new Request('GET', '/hello'))->body)->toBe($body);
		expect($app->container->get(Session::class))->toBeInstanceOf(Session::class);
	})->with([
		'debug on' => [['debug' => true], true, "<p>hello from dev</p>\n"],
		'no settings at all' => [[], false, "<p>hello from production</p>\n"],
	]);

	test('sets the default timezone from the config', function () {
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

	test('configures the built-in services from the config', function () {
		$key = base64_encode(random_bytes(32));
		$app = App::boot(BOOT, new Config([
			'session_lifetime' => 3600,
			'app_key' => $key,
			'cors_origins' => ['https://app.example'],
			'content_policy' => ['img-src' => "'self'"],
		]));
		$app->scan(BOOT . '/App', 'Sapling\Tests\Fixtures\Boot\App');
		$app->middleware(Cors::class);
		$app->middleware(SecurityHeaders::class);

		$app->container->get(Session::class)->get('anything');
		$stored = $app->container->get(Crypto::class)->encrypt('secret', 'test');
		$response = $app->handle(new Request('GET', '/hello', headers: ['Origin' => 'https://app.example']));

		expect(session_get_cookie_params()['lifetime'])->toBe(3600);
		expect(ini_get('session.gc_maxlifetime'))->toBe('3600');
		expect(new Crypto($key)->decrypt($stored, 'test'))->toBe('secret');
		expect($response->headers['Access-Control-Allow-Origin'])->toBe('https://app.example');
		expect($response->headers['Content-Security-Policy'])->toContain("img-src 'self';");
	});

	test('refuses a string where a setting must be a bool', function (string $key) {
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
});

describe('registering in code', function () {
	test('scan() ignores trailing slashes and non-class files', function (string $folder, string $namespace) {
		$app = new App(FIXTURES);
		$app->scan($folder, $namespace);

		expect($app->handle(new Request('GET', '/notes'))->status)->toBe(200);
		expect(function_exists('scan_fixture_helper'))->toBeFalse();
		expect(function_exists('scan_fixture_shared'))->toBeFalse();
	})->with([
		'without' => [FIXTURES . '/Scan', 'Sapling\Tests\Fixtures\Scan'],
		'with' => [FIXTURES . '/Scan/', 'Sapling\Tests\Fixtures\Scan\\'],
	]);

	test('add() refuses a class with no #[Route] or #[Command]', function () {
		$app = new App(FIXTURES);

		expect(fn() => $app->add(Clock::class))
			->toThrow(LogicException::class, 'Clock has no #[Route] or #[Command] to add');
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

	test('a class can have more than one #[Route]', function () {
		$app = new App(FIXTURES);
		$app->add(NoteForm::class);

		$new = $app->handle(new Request('GET', '/notes/form'));
		$edit = $app->handle(new Request('GET', '/notes/form/7'));

		expect([$new->body, $edit->body])->toBe(['form for a new note', 'form for 7']);
	});
});

describe('middleware', function () {
	test('global middleware runs first, then the action\'s in attribute order', function () {
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

	test('middleware can return early, and nothing inside it runs', function (
		array $middleware,
		string $path,
		array $events,
	) {
		$app = scannedApp();

		foreach ($middleware as $layer) {
			$app->middleware($layer);
		}

		$response = $app->handle(new Request('GET', $path));

		expect([$response->status, $response->body])->toBe([403, 'denied']);
		expect($app->container->get(Trace::class)->events)->toBe($events);
	})->with([
		'action middleware' => [[GlobalOne::class], '/blocked', ['GlobalOne in', 'Deny', 'GlobalOne out']],
		'global middleware, before routing' => [[Deny::class, GlobalOne::class], '/nowhere', ['Deny']],
	]);

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

	test('an HttpException from middleware reaches outer middleware as a response', function () {
		$app = scannedApp();
		$app->middleware(Stamp::class);
		$app->middleware(fn() => throw new HttpException(429, 'Slow down'));

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

describe('passing data to the action', function () {
	test('an action is built after its middleware runs', function () {
		$app = new App(FIXTURES);
		$app->middleware(function (Request $request, Closure $next) use ($app): Response {
			$app->container->set(CurrentUser::class, new CurrentUser('ann'));

			return $next($request);
		});
		$app->router->add('GET', '/profile', Profile::class);

		$response = $app->handle(new Request('GET', '/profile'));

		expect($response->body)->toBe('Signed in as ann');
	});
});

describe('errors', function () {
	test('only a 5xx or a crash is logged', function (string $path, int $status, string $body, array $logged) {
		[$response, $log] = captureErrorLog(fn() => scannedApp()->handle(new Request('GET', $path)));

		expect([$response->status, $response->body])->toBe([$status, $body]);
		expect($response->headers['Content-Type'])->toBe('text/plain; charset=utf-8');
		expect($log)->toHaveCount(count($logged));

		foreach ($logged as $index => $line) {
			expect($log[$index])->toContain($line);
		}
	})->with([
		'a 403' => ['/forbidden', 403, 'Forbidden', []],
		'a 503' => ['/unavailable', 503, 'Down for maintenance', ['GET /unavailable failed: 503']],
		'a crash' => ['/fails', 500, 'Internal Server Error', ['GET /fails failed: RuntimeException: boom <script>']],
		'a warning, turned into a crash' => [
			'/warns',
			500,
			'Internal Server Error',
			['GET /warns failed: ErrorException: Undefined array key 0'],
		],
	]);

	test('a 5xx response an action returns is not logged', function () {
		$app = new App(FIXTURES);
		$app->router->add('GET', '/busy', fn() => Response::text('Busy', 503));

		[$response, $log] = captureErrorLog(fn() => $app->handle(new Request('GET', '/busy')));

		expect($response->status)->toBe(503);
		expect($log)->toBe([]);
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

	test('deprecations are not turned into exceptions', function () {
		$response = httpRequest('GET', '/deprecated');

		expect([$response['status'], $response['body']])->toBe([200, 'still works']);
	});

	test('a crash in a package logs the line of your code that led there', function () {
		$app = new App(FIXTURES);
		$app->router->add('GET', '/report', require FIXTURES . '/missing-download.php');

		[, $log] = captureErrorLog(fn() => $app->handle(new Request('GET', '/report')));

		expect($log[0])
			->toContain('File not found: /no/such/report.pdf in ')
			->toContain('src/Http/Response.php:')
			->toEndWith(' from ' . FIXTURES . '/missing-download.php:5');
	});
});

describe('crashes', function () {
	test('a crash reaches the middleware outside it as a 500', function (array $middleware, Closure $action) {
		$app = new App(FIXTURES);
		$app->middleware(new Cors(['https://app.example']));

		foreach ($middleware as $layer) {
			$app->middleware($layer);
		}

		$app->router->add('GET', '/boom', $action);
		$request = new Request('GET', '/boom', headers: ['Origin' => 'https://app.example']);

		[$response] = captureErrorLog(fn() => $app->handle($request));

		expect($response->status)->toBe(500);
		expect($response->header('Access-Control-Allow-Origin')->string())->toBe('https://app.example');
	})->with([
		'in the action' => [[], fn() => throw new RuntimeException('boom')],
		'in middleware' => [[fn() => throw new RuntimeException('boom')], fn() => Response::text('never')],
	]);

	test('a crash in middleware is logged once', function () {
		$app = new App(FIXTURES);
		$app->middleware(fn() => throw new RuntimeException('boom'));
		$app->router->add('GET', '/boom', fn() => Response::text('never'));

		[, $log] = captureErrorLog(fn() => $app->handle(new Request('GET', '/boom')));

		expect($log)->toHaveCount(1);
		expect($log[0])->toContain('GET /boom failed: RuntimeException: boom');
	});
});

describe('onError()', function () {
	test('the handler\'s response passes back through middleware', function () {
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

	test('adds the exception\'s headers without replacing the handler\'s', function () {
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

	test('builds a handler class only when an error happens', function () {
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

describe('onError() for crashes', function () {
	test('outside debug mode the handler answers a crash', function () {
		$app = new App(FIXTURES);
		$app->onError(fn(HttpException $e, Request $request): Response => Response::text(
			get_debug_type($e->getPrevious()),
			$e->status,
		));
		$app->router->add('GET', '/boom', fn() => throw new RuntimeException('boom'));

		[$response] = captureErrorLog(fn() => $app->handle(new Request('GET', '/boom')));

		expect($response->status)->toBe(500);
		expect($response->body)->toBe('RuntimeException');
	});

	test('in debug mode a crash skips the handler', function () {
		$app = new App(FIXTURES, debug: true);
		$app->onError(fn(HttpException $e, Request $request): Response => Response::text('handled', $e->status));
		$app->router->add('GET', '/boom', fn() => throw new RuntimeException('boom'));

		[$response] = captureErrorLog(fn() => $app->handle(new Request('GET', '/boom')));

		expect($response->body)->toContain('<h1>RuntimeException</h1>');
	});

	test('a crash in the handler is logged once', function (string $path, array $logged) {
		$app = new App(FIXTURES);
		$app->middleware(new Cors(['https://app.example']));
		$app->onError(fn() => throw new LogicException('handler broke'));
		$app->router->add('GET', '/boom', fn() => throw new RuntimeException('boom'));
		$request = new Request('GET', $path, headers: ['Origin' => 'https://app.example']);

		[$response, $log] = captureErrorLog(fn() => $app->handle($request));

		expect($response->status)->toBe(500);
		expect($response->header('Access-Control-Allow-Origin')->string())->toBe('https://app.example');
		expect($log)->toHaveCount(count($logged));

		foreach ($logged as $index => $line) {
			expect($log[$index])->toContain($line);
		}
	})->with([
		'answering a 404' => ['/nowhere', ['GET /nowhere failed: LogicException: handler broke']],
		'answering a crash' => [
			'/boom',
			['GET /boom failed: RuntimeException: boom', 'GET /boom failed: LogicException: handler broke'],
		],
	]);
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
	test('an app closure is shown by its relative file', function (string $base) {
		$app = new App($base, debug: true);
		$app->router->add('GET', '/closure', require FIXTURES . '/closure-route.php');

		[$response] = captureErrorLog(fn() => $app->handle(new Request('GET', '/closure')));

		expect(explode('<details', $response->body)[0])->toContain('{closure:closure-route.php:3}()');
	})->with(['without' => FIXTURES, 'with' => FIXTURES . '/']);
});

describe('commands', function () {
	test('a warning in a command prints its trace once', function () {
		$result = runConsole(['crash']);

		expect($result['code'])->toBe(1);
		expect($result['stdout'])->toBe('');
		expect(substr_count($result['stderr'], 'Undefined array key 0'))->toBe(1);
		expect($result['stderr'])->toContain('Stack trace:');
	});

	test('a crashed command logs one line when error_log is set', function (string $command, string $error) {
		$log = (string) tempnam(sys_get_temp_dir(), 'sapling-log');

		try {
			$result = runConsole([$command], ['error_log' => $log]);

			expect($result['code'])->toBe(1);
			expect($result['stderr'])->toContain('Stack trace:');
			expect((string) file_get_contents($log))->toContain("command {$command} failed: {$error}");
		} finally {
			unlink($log);
		}
	})->with([
		'a warning' => ['crash', 'ErrorException: Undefined array key 0'],
		'an InvalidArgumentException from a bug' => [
			'misconfigured',
			'InvalidArgumentException: app_key must be 32 bytes',
		],
	]);

	test('a UsageException prints only its message', function () {
		$result = runConsole(['greet', '--times', '2']);

		expect($result['code'])->toBe(1);
		expect($result['stderr'])->toBe("--times needs a value, written --times=value\n");
	});

	test('the same command name on two classes throws and names both', function () {
		$app = scannedApp();

		expect(fn() => $app->add(DuplicateGreet::class))
			->toThrow(
				LogicException::class,
				'Duplicate command greet: ' . Greet::class . ' and ' . DuplicateGreet::class,
			);
	});

	test('run() from the CLI exits with the code the command returns', function () {
		$greet = runConsole(['greet', 'Ann', '--shout']);
		$fail = runConsole(['fail']);

		expect($greet)->toBe(['stdout' => "Hello Ann!\n", 'stderr' => '', 'code' => 0]);
		expect($fail)->toBe(['stdout' => '', 'stderr' => "failing on purpose\n", 'code' => 3]);
	});

	test('no command, or an unknown one, lists every command', function (array $args, int $code, string $stderr) {
		$result = runConsole($args);

		expect($result['code'])->toBe($code);
		expect($result['stderr'])->toBe($stderr);
		expect($result['stdout'])
			->toBe("Commands:\n  commands:list  List every command\n\n  crash          Trigger a warning\n  fail           Exit with code 3\n  greet          Say hello to arg 0\n  misconfigured  Throw an InvalidArgumentException, as a bug does\n\n  routes:list    List every route and its class\n");
	})->with([
		'no command, which runs commands:list' => [[], 0, ''],
		'an unknown command' => [['nope'], 1, "Unknown command: nope\n"],
	]);
});
