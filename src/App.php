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
use Sapling\Http\DebugPage;
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

		$this->onError(fn(HttpException $e) => Response::text($e->getMessage(), $e->status));
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
			$this->add($class);
		}
	}

	/**
	 * Registers one class, as scan() does for a folder.
	 *
	 * @param class-string $class
	 */
	public function add(string $class): void
	{
		$reflection = new \ReflectionClass($class);
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
			return $this->answer([...$middleware, $this->dispatch(...)], $request);
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * An unknown command prints the list of commands and returns 1.
	 */
	public function runCommand(Console $console): int
	{
		$name = $console->command === '' ? ListCommands::NAME : $console->command;
		$action = $this->commands->find($name);
		if ($action === null) {
			$console->error("Unknown command: {$name}");
			$this->resolve(ListCommands::class)($console);
			return Console::FAILURE;
		}

		set_error_handler($this->throwOnError(...));
		try {
			return $this->resolve($action)($console);
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

	/** @param class-string|object $handler */
	private function resolve(string|object $handler): callable
	{
		return is_string($handler) ? $this->container->get($handler) : $handler;
	}

	/** @param non-empty-list<class-string|object> $layers */
	private function answer(array $layers, Request $request): Response
	{
		$layer = array_shift($layers);
		try {
			if ($layers === []) {
				return $this->resolve($layer)($request);
			}

			$next = fn(Request $request) => $this->answer($layers, $request);
			return $this->resolve($layer)($request, $next);
		} catch (HttpException $e) {
			if ($e->status >= 500) {
				error_log("{$request->method} {$request->path} failed: {$e->status}");
			}

			return $this->answerError($e, $request);
		} catch (\Throwable $e) {
			new CrashLog($this->basePath)->write("{$request->method} {$request->path}", $e);
			if ($this->debug) {
				return Response::html(DebugPage::render($e, $this->basePath), 500);
			}

			return $this->answerError(new HttpException(500, previous: $e), $request);
		}
	}

	/** @throws HttpException */
	private function dispatch(Request $request): Response
	{
		$route = $this->router->match($request->method, $request->path);
		$request->params = $route['params'];
		return $this->answer([...$route['middleware'], $route['action']], $request);
	}

	private function answerError(HttpException $e, Request $request): Response
	{
		try {
			/** @var callable(HttpException, Request): Response $handler */
			$handler = $this->resolve($this->errorHandler);
			$response = $handler($e, $request);
		} catch (\Throwable $crash) {
			new CrashLog($this->basePath)->write("{$request->method} {$request->path}", $crash);
			if ($this->debug) {
				return Response::html(DebugPage::render($crash, $this->basePath), 500);
			}

			return Response::text('Internal Server Error', 500);
		}

		foreach ($e->headers as $name => $value) {
			if ($response->header($name)->raw !== null) {
				continue;
			}

			$response = $response->withHeader($name, $value);
		}

		return $response;
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
