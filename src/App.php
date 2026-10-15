<?php

declare(strict_types=1);

namespace Sapling;

use Closure;
use DateInvalidTimeZoneException;
use DateTimeZone;
use ErrorException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Sapling\Attributes\Command;
use Sapling\Attributes\Middleware;
use Sapling\Attributes\Route;
use Sapling\Cli\CommandList;
use Sapling\Cli\Commands\ListCommands;
use Sapling\Cli\Commands\ListRoutes;
use Sapling\Cli\Commands\NewMigration;
use Sapling\Cli\Commands\RunMigrations;
use Sapling\Cli\Console;
use Sapling\Http\HttpException;
use Sapling\Http\Middleware\Cors;
use Sapling\Http\Middleware\Csrf;
use Sapling\Http\Request;
use Sapling\Http\Response;
use Sapling\Http\Router;
use Sapling\Http\Session;
use SplFileInfo;
use Throwable;

/**
 * Holds the container, routes, commands and middleware, and answers requests or runs commands.
 */
final class App
{
	public readonly Container $container;

	public readonly Router $router;

	public readonly CommandList $commands;

	/** @var list<array{middleware: class-string|object, prefix: string}> */
	private array $middleware = [];

	/** @var class-string|object */
	private string|object $httpExceptionHandler;

	/**
	 * A bare app with only the commands:list command, for tests and one-file apps. Use boot() to build an app from
	 * its config.
	 */
	public function __construct(public readonly string $basePath, public readonly bool $debug = false)
	{
		$this->container = new Container();
		$this->router = new Router();
		$this->commands = new CommandList();

		$this->container->set(self::class, $this);
		$this->container->set(Container::class, $this->container);
		$this->container->set(Router::class, $this->router);
		$this->container->set(CommandList::class, $this->commands);
		$this->container->set(Template::class, fn(): Template => new Template($this->path('views')));

		$this->commands->add(ListCommands::NAME, ListCommands::class, ListCommands::DESCRIPTION);

		$this->httpExceptionHandler(Response::fromHttpException(...));
	}

	/**
	 * Builds the app from its config: debug mode, the timezone, Session, Crypto, Cors, Csrf and the migrations and
	 * routes commands.
	 *
	 * @throws DateInvalidTimeZoneException When the timezone setting isn't a known timezone.
	 */
	public static function boot(string $basePath, Config $config): self
	{
		$app = new self($basePath, $config->get('debug', false));
		$timezone = $config->get('timezone', null);

		if ($timezone !== null) {
			$zone = new DateTimeZone($timezone);
			date_default_timezone_set($zone->getName());
		}

		$app->container->set(Config::class, $config);
		$app->container->set(Crypto::class, fn(): Crypto => new Crypto($config->get('app_key')));
		$app->container->set(Session::class, fn(): Session => new Session($config->get('session_lifetime', 0)));

		$app->container->set(Cors::class, function () use ($config): Cors {
			$trusted = $config->get('cors_origins', []);
			$creds = $config->get('cors_credentials', false);
			$expose = $config->get('cors_expose_headers', []);

			return new Cors($trusted, $creds, $expose);
		});

		$app->container->set(Csrf::class, function () use ($config): Csrf {
			$trusted = array_diff($config->get('cors_origins', []), ['*']);

			return new Csrf(array_values($trusted));
		});

		$app->commands->add(NewMigration::NAME, NewMigration::class, NewMigration::DESCRIPTION);
		$app->commands->add(RunMigrations::NAME, RunMigrations::class, RunMigrations::DESCRIPTION);
		$app->commands->add(ListRoutes::NAME, ListRoutes::class, ListRoutes::DESCRIPTION);

		return $app;
	}

	/**
	 * An absolute path inside the app, such as path('views').
	 */
	public function path(string $relative = ''): string
	{
		$base = rtrim($this->basePath, '/\\');
		$relative = ltrim($relative, '/\\');

		return "{$base}/{$relative}";
	}

	/**
	 * Registers every class in the folder and its subfolders by its #[Route], #[Middleware] and #[Command]
	 * attributes. The namespace needs no trailing backslash, as in scan(__DIR__ . '/app', 'App').
	 */
	public function scan(string $dir, string $namespace): void
	{
		$dir = rtrim($dir, '/\\');
		$namespace = rtrim($namespace, '\\') . '\\';
		$classes = [];
		$directory = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);

		/** @var SplFileInfo $file */
		foreach (new RecursiveIteratorIterator($directory) as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$relative = substr($file->getPathname(), strlen($dir) + 1, -4);
			$classes[] = $namespace . str_replace('/', '\\', $relative);
		}

		sort($classes);

		foreach ($classes as $class) {
			$this->add($class);
		}
	}

	/**
	 * Registers one class by its #[Route], #[Middleware] and #[Command] attributes.
	 *
	 * @param class-string $class
	 */
	public function add(string $class): void
	{
		$reflection = new ReflectionClass($class);
		$middleware = [];

		foreach ($reflection->getAttributes(Middleware::class) as $attribute) {
			$middleware[] = $attribute->newInstance()->class;
		}

		foreach ($reflection->getAttributes(Route::class) as $attribute) {
			$route = $attribute->newInstance();
			$this->router->add($route->method, $route->path, $class, $middleware);
		}

		foreach ($reflection->getAttributes(Command::class) as $attribute) {
			$command = $attribute->newInstance();
			$this->commands->add($command->name, $class, $command->description);
		}
	}

	/**
	 * Adds middleware around every request, in the order added. With a prefix, it only runs for that path and the
	 * paths below it.
	 *
	 * @param class-string|object $middleware
	 */
	public function middleware(string|object $middleware, string $prefix = ''): void
	{
		$this->middleware[] = ['middleware' => $middleware, 'prefix' => rtrim($prefix, '/')];
	}

	/**
	 * Answers the current request, or runs a command when started from the terminal.
	 */
	public function run(): void
	{
		if (PHP_SAPI === 'cli') {
			$console = new Console($_SERVER['argv'] ?? []);
			exit($this->runCommand($console));
		}

		$request = Request::fromGlobals();
		$response = $this->handle($request);

		// HEAD gets the headers and no body, so a download isn't read for nothing.
		$response->send(body: $request->method !== 'HEAD');
	}

	/**
	 * Turns a request into a response. An error becomes a response too, so it never throws.
	 */
	public function handle(Request $request): Response
	{
		$middleware = [];

		foreach ($this->middleware as $registered) {
			if (!str_starts_with("{$request->path}/", "{$registered['prefix']}/")) {
				continue;
			}

			$middleware[] = $registered['middleware'];
		}

		set_error_handler($this->throwOnError(...));

		try {
			$response = $this->pipeline($middleware, $this->dispatch(...))($request);
		} catch (Throwable $e) {
			new CrashLog($this->basePath)->write("{$request->method} {$request->path}", $e);

			return Response::exception($e, $this->debug, $this->basePath);
		} finally {
			restore_error_handler();
		}

		if ($response->status >= 500) {
			error_log("{$request->method} {$request->path} failed: {$response->status}");
		}

		return $response;
	}

	/**
	 * Runs a command and returns its exit code. An unknown command prints the list of commands and returns 1.
	 */
	public function runCommand(Console $console): int
	{
		$name = $console->command;

		if ($name === '') {
			$name = ListCommands::NAME;
		}

		$action = $this->commands->find($name);

		if ($action === null) {
			$console->error("Unknown command: {$name}");
			$list = $this->resolve(ListCommands::class);
			$list($console);

			return Console::FAILURE;
		}

		set_error_handler($this->throwOnError(...));

		try {
			/** @var callable(Console): int $command */
			$command = $this->resolve($action);

			return $command($console);
		} catch (Throwable $e) {
			new CrashLog($this->basePath)->write("command {$name}", $e);
			$console->error((string) $e);

			return Console::FAILURE;
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Sets what turns an HttpException, such as a 404 or abort(403), into a response. It's a class name, an object or a
	 * closure, called with the exception and the request. The default answers in plain text.
	 *
	 * @param class-string|object $handler
	 */
	public function httpExceptionHandler(string|object $handler): void
	{
		$this->httpExceptionHandler = $handler;
	}

	/** @throws HttpException */
	private function dispatch(Request $request): Response
	{
		$route = $this->router->match($request->method, $request->path);
		$request->params = $route['params'];
		$action = function (Request $request) use ($route): Response {
			/** @var callable(Request): Response $handler */
			$handler = $this->resolve($route['action']);

			return $handler($request);
		};

		$run = $this->pipeline($route['middleware'], $action);

		return $run($request);
	}

	/**
	 * @param list<class-string|object> $middleware
	 * @param Closure(Request): Response $next
	 * @return Closure(Request): Response
	 */
	private function pipeline(array $middleware, Closure $next): Closure
	{
		$next = $this->answerErrors($next);

		foreach (array_reverse($middleware) as $layer) {
			$inner = $next;
			$next = $this->answerErrors(function (Request $request) use ($layer, $inner): Response {
				/** @var callable(Request, Closure): Response $middleware */
				$middleware = $this->resolve($layer);

				return $middleware($request, $inner);
			});
		}

		return $next;
	}

	/**
	 * @param Closure(Request): Response $step
	 * @return Closure(Request): Response
	 */
	private function answerErrors(Closure $step): Closure
	{
		return function (Request $request) use ($step): Response {
			try {
				return $step($request);
			} catch (HttpException $e) {
				/** @var callable(HttpException, Request): Response $handler */
				$handler = $this->resolve($this->httpExceptionHandler);

				return $handler($e, $request);
			}
		};
	}

	/** @param class-string|object $handler */
	private function resolve(string|object $handler): callable
	{
		return is_string($handler) ? $this->container->get($handler) : $handler;
	}

	private function throwOnError(int $severity, string $message, string $file, int $line): bool
	{
		if (!(error_reporting() & $severity)) {
			return false;
		}

		if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
			return false;
		}

		throw new ErrorException($message, 0, $severity, $file, $line);
	}
}
