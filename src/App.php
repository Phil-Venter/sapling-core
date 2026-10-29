<?php

declare(strict_types=1);

namespace Sapling;

use Sapling\Attributes\Command;
use Sapling\Attributes\Middleware;
use Sapling\Attributes\Route;
use Sapling\Cli\CommandList;
use Sapling\Cli\Commands\ListCommands;
use Sapling\Cli\Commands\ListMigrations;
use Sapling\Cli\Commands\ListRoutes;
use Sapling\Cli\Commands\NewMigration;
use Sapling\Cli\Commands\RunMigrations;
use Sapling\Cli\Console;
use Sapling\Cli\UsageException;
use Sapling\Http\HttpException;
use Sapling\Http\Middleware\Cors;
use Sapling\Http\Middleware\Csrf;
use Sapling\Http\Middleware\SecurityHeaders;
use Sapling\Http\Request;
use Sapling\Http\Response;
use Sapling\Http\Router;
use Sapling\Http\Session;

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
	private string|object $errorHandler;

	/** @var list<string> */
	private array $trustedProxies = [];

	/**
	 * A bare app with only the commands:list and routes:list commands, for tests and one-file apps. Use boot() to build an app from its config.
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
		$this->container->set(Template::class, fn() => new Template($this->path('views')));

		$this->commands->add(ListCommands::NAME, ListCommands::class, ListCommands::DESCRIPTION);
		$this->commands->add(ListRoutes::NAME, ListRoutes::class, ListRoutes::DESCRIPTION);

		$this->onError(Response::error(...));
	}

	/**
	 * Builds the app from its config: debug mode, the timezone, trusted proxies, Session, Crypto, SecurityHeaders, Cors, Csrf and the migrations commands.
	 *
	 * @throws \DateInvalidTimeZoneException When the timezone setting isn't a known timezone.
	 */
	public static function boot(string $basePath, Config $config): self
	{
		$app = new self($basePath, $config->get('debug', false));
		$timezone = $config->get('timezone', null);
		if ($timezone !== null) {
			$zone = new \DateTimeZone($timezone);
			date_default_timezone_set($zone->getName());
		}

		$app->trustedProxies = $config->get('trusted_proxies', []);

		$app->container->set(Config::class, $config);
		$app->container->set(Session::class, fn() => new Session($config->get('session_lifetime', 0)));
		$app->container->set(Crypto::class, fn() => new Crypto($config->get('app_key')));
		$app->container->set(SecurityHeaders::class, fn() => new SecurityHeaders($config->get('content_policy', [])));
		$app->container->set(
			Cors::class,
			fn() => new Cors($config->get('cors_origins', []), $config->get('cors_credentials', false)),
		);
		$app->container->set(Csrf::class, fn() => new Csrf($config->get('cors_origins', [])));

		$app->commands->add(NewMigration::NAME, NewMigration::class, NewMigration::DESCRIPTION);
		$app->commands->add(RunMigrations::NAME, RunMigrations::class, RunMigrations::DESCRIPTION);
		$app->commands->add(ListMigrations::NAME, ListMigrations::class, ListMigrations::DESCRIPTION);

		return $app;
	}

	/**
	 * Registers each class with a #[Route] or #[Command] in the folder and its subfolders, as in scan(__DIR__ . '/app', 'App').
	 *
	 * @throws \UnexpectedValueException When the folder doesn't exist.
	 */
	public function scan(string $dir, string $namespace): void
	{
		$dir = rtrim($dir, '/\\');
		$namespace = rtrim($namespace, '\\') . '\\';
		$classes = [];
		$directory = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
		/** @var \SplFileInfo $file */
		foreach (new \RecursiveIteratorIterator($directory) as $file) {
			$name = $file->getBasename('.php');
			if ($file->getExtension() !== 'php' || preg_match('/^[A-Z]/', $name) !== 1 || str_contains($name, '.')) {
				continue;
			}

			$relative = substr($file->getPathname(), strlen($dir) + 1, -4);
			$classes[] = $namespace . str_replace('/', '\\', $relative);
		}

		sort($classes);
		foreach ($classes as $class) {
			$this->register($class);
		}
	}

	/**
	 * Registers one class, as scan() does for a folder. Throws a LogicException when it has no #[Route] or #[Command].
	 *
	 * @param class-string $class
	 */
	public function add(string $class): void
	{
		if (!$this->register($class)) {
			throw new \LogicException("{$class} has no #[Route] or #[Command]");
		}
	}

	/**
	 * Runs around every request, in the order added. A prefix limits it to that path and the paths below it.
	 *
	 * @param class-string|object $middleware
	 */
	public function middleware(string|object $middleware, string $prefix = ''): void
	{
		$prefix = trim($prefix, '/');
		if ($prefix !== '') {
			$prefix = "/{$prefix}";
		}

		$this->middleware[] = ['middleware' => $middleware, 'prefix' => $prefix];
	}

	/**
	 * Sets the handler that turns an HttpException into a response, given the exception and the request. The default answers in plain text. Outside debug mode, a crash reaches it as a 500 whose previous exception is the crash.
	 *
	 * @param class-string|object $handler
	 */
	public function onError(string|object $handler): void
	{
		$this->errorHandler = $handler;
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

		$request = Request::fromGlobals($this->trustedProxies);
		$response = $this->handle($request);
		$response->send(body: $request->method !== 'HEAD');
	}

	/**
	 * Never throws. An error or a crash comes back as a response.
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
			return $this->pipeline($middleware, $this->dispatch(...))($request);
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * An unknown command prints the list of commands and returns 1.
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
		} catch (UsageException $e) {
			$console->error($e->getMessage());
			return Console::FAILURE;
		} catch (\Throwable $e) {
			if (ini_get('error_log') !== '') {
				new CrashLog($this->basePath)->write("command {$name}", $e);
			}

			$console->error((string) $e);
			return Console::FAILURE;
		} finally {
			restore_error_handler();
		}
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

	/** @param class-string $class */
	private function register(string $class): bool
	{
		$reflection = new \ReflectionClass($class);
		$routes = $reflection->getAttributes(Route::class);
		$commands = $reflection->getAttributes(Command::class);
		$middleware = [];
		foreach ($reflection->getAttributes(Middleware::class) as $attribute) {
			$middleware[] = $attribute->newInstance()->class;
		}

		foreach ($routes as $attribute) {
			$route = $attribute->newInstance();
			$this->router->add($route->method, $route->path, $class, $middleware);
		}

		foreach ($commands as $attribute) {
			$command = $attribute->newInstance();
			$this->commands->add($command->name, $class, $command->description);
		}

		return $routes !== [] || $commands !== [];
	}

	/** @param class-string|object $handler */
	private function resolve(string|object $handler): callable
	{
		if (!is_string($handler)) {
			return $handler;
		}

		return $this->container->get($handler);
	}

	/**
	 * @param list<class-string|object> $middleware
	 * @param \Closure(Request): Response $next
	 * @return \Closure(Request): Response
	 */
	private function pipeline(array $middleware, \Closure $next): \Closure
	{
		$next = $this->answerErrors($next);
		foreach (array_reverse($middleware) as $layer) {
			$inner = $next;
			$next = $this->answerErrors(function (Request $request) use ($layer, $inner): Response {
				/** @var callable(Request, \Closure): Response $middleware */
				$middleware = $this->resolve($layer);
				return $middleware($request, $inner);
			});
		}

		return $next;
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

		return $this->pipeline($route['middleware'], $action)($request);
	}

	/**
	 * @param \Closure(Request): Response $step
	 * @return \Closure(Request): Response
	 */
	private function answerErrors(\Closure $step): \Closure
	{
		return function (Request $request) use ($step): Response {
			try {
				return $step($request);
			} catch (HttpException $e) {
				if ($e->status >= 500) {
					error_log("{$request->method} {$request->path} failed: {$e->status}");
				}

				return $this->answerError($e, $request);
			} catch (\Throwable $e) {
				return $this->answerCrash($e, $request);
			}
		};
	}

	private function answerError(HttpException $e, Request $request): Response
	{
		try {
			/** @var callable(HttpException, Request): Response $handler */
			$handler = $this->resolve($this->errorHandler);
			$response = $handler($e, $request);
		} catch (\Throwable $crash) {
			// Not answerCrash(), which would call the broken handler again.
			new CrashLog($this->basePath)->write("{$request->method} {$request->path}", $crash);
			return Response::fromCrash($crash, $this->debug, $this->basePath);
		}

		foreach ($e->headers as $name => $value) {
			if ($response->header($name)->raw !== null) {
				continue;
			}

			$response = $response->withHeader($name, $value);
		}

		return $response;
	}

	private function answerCrash(\Throwable $e, Request $request): Response
	{
		new CrashLog($this->basePath)->write("{$request->method} {$request->path}", $e);
		if ($this->debug) {
			return Response::fromCrash($e, true, $this->basePath);
		}

		return $this->answerError(new HttpException(500, previous: $e), $request);
	}

	private function throwOnError(int $severity, string $message, string $file, int $line): bool
	{
		if (!(error_reporting() & $severity)) {
			return false;
		}

		if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
			return false;
		}

		throw new \ErrorException($message, 0, $severity, $file, $line);
	}
}
