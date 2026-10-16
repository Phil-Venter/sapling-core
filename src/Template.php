<?php

namespace Sapling;

use BackedEnum;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Renders plain PHP templates, with layouts, sections, partials and components.
 */
final class Template
{
	/** @var array<string, mixed> */
	private array $shared = [];

	/** @var array<string, string> */
	private array $sections = [];

	/** @var list<array{kind: 'section'|'component', name: string, data: array<string, mixed>}> */
	private array $open = [];

	private ?string $layout = null;

	private bool $rendering = false;

	/** @var array<string, string> */
	private array $prefixes = [];

	/**
	 * Templates are $dir/{name}.{$suffix}, such as views/notes.tmpl.php.
	 */
	public function __construct(private string $dir, private string $suffix = 'tmpl.php') {}

	/**
	 * Lets prefix::name find templates in another folder, as in prefix('notes', __DIR__ . '/app/Notes') for
	 * include('notes::list').
	 */
	public function prefix(string $prefix, string $dir): void
	{
		$this->prefixes[$prefix] = rtrim($dir, '/\\');
	}

	/**
	 * Makes a value available to every template rendered after this.
	 */
	public function share(string $key, mixed $value): void
	{
		$this->shared[$key] = $value;
	}

	/**
	 * Wraps this template in another, which prints it with yield('content'). Call it from a template.
	 */
	public function layout(string $template): void
	{
		$this->layout = $template;
	}

	/**
	 * Renders the page {$template}.{$suffix}, inside its layout. Use include() inside a template.
	 *
	 * @param array<string, mixed> $data
	 * @throws RuntimeException When a template file is missing.
	 * @throws Throwable Whatever a template throws.
	 */
	public function render(string $template, array $data = []): string
	{
		// It would reset the page being rendered.
		if ($this->rendering) {
			throw new LogicException("render() inside a template, use include('{$template}')");
		}

		$this->rendering = true;
		$this->sections = [];
		$this->open = [];
		$this->layout = null;

		try {
			$html = $this->capture($template, $data);

			while ($this->layout !== null) {
				$layout = $this->layout;
				$this->layout = null;
				$this->sections['content'] = $html;
				$html = $this->capture($layout, $data);
			}

			return $html;
		} finally {
			$this->rendering = false;
		}
	}

	/**
	 * Renders a partial, as in include('_nav').
	 *
	 * @param array<string, mixed> $data
	 * @throws RuntimeException When the template file is missing.
	 * @throws Throwable Whatever the template throws.
	 */
	public function include(string $template, array $data = []): string
	{
		// A partial's layout() would otherwise become the page's.
		$layout = $this->layout;
		$html = $this->capture($template, $data);
		$this->layout = $layout;

		return $html;
	}

	/**
	 * Sets a section, as in section('title', 'Notes'), or starts one that end() closes, as in section('aside').
	 * Call it from a template.
	 */
	public function section(string $name, string|int|float|BackedEnum $content = ''): void
	{
		if (func_num_args() === 1) {
			$this->open[] = ['kind' => 'section', 'name' => $name, 'data' => []];
			ob_start();

			return;
		}

		if ($content instanceof BackedEnum) {
			$content = $content->value;
		}

		$this->sections[$name] = (string) $content;
	}

	/**
	 * Starts a component, as in component('_card', ['title' => 'Notes']). Its $slot is what's printed until end().
	 *
	 * @param array<string, mixed> $data
	 */
	public function component(string $template, array $data = []): void
	{
		$this->open[] = ['kind' => 'component', 'name' => $template, 'data' => $data];
		ob_start();
	}

	/**
	 * Closes the last section() or component().
	 *
	 * @throws RuntimeException When a component's template file is missing.
	 * @throws Throwable Whatever a component's template throws.
	 */
	public function end(): void
	{
		$open = array_pop($this->open);

		if ($open === null) {
			throw new LogicException('end() without an open section() or component()');
		}

		$content = (string) ob_get_clean();

		if ($open['kind'] === 'section') {
			$this->sections[$open['name']] = $content;

			return;
		}

		echo $this->include($open['name'], [...$open['data'], 'slot' => $content]);
	}

	/**
	 * A section's content, or $default when it wasn't set. A layout prints it with <?= $this->yield('title') ?>.
	 */
	public function yield(string $name, string $default = ''): string
	{
		return $this->sections[$name] ?? $default;
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws RuntimeException
	 * @throws Throwable
	 */
	private function capture(string $template, array $data): string
	{
		$file = $this->file($template);
		$level = ob_get_level();
		$opened = count($this->open);
		$run =
			/** @param array<string, mixed> $__data */
			function (string $__file, array $__data): void {
				extract($__data, EXTR_SKIP);
				require $__file;
			};

		ob_start();

		try {
			$run($file, $data + $this->shared);

			// Before ob_get_clean(), which would otherwise read the unclosed one's buffer.
			$unclosed = $this->open[$opened] ?? null;

			if ($unclosed !== null) {
				$kind = ucfirst($unclosed['kind']);
				throw new LogicException("{$kind} {$unclosed['name']} in {$template} has no end()");
			}
		} catch (Throwable $e) {
			while (ob_get_level() > $level) {
				ob_end_clean();
			}

			throw $e;
		}

		return (string) ob_get_clean();
	}

	/** @throws RuntimeException */
	private function file(string $template): string
	{
		$dir = $this->dir;
		$name = $template;

		if (str_contains($template, '::')) {
			$parts = explode('::', $template, 2);
			$prefix = $parts[0];
			$name = $parts[1] ?? '';

			if (!array_key_exists($prefix, $this->prefixes)) {
				throw new LogicException("Unknown template prefix {$prefix} in {$template}");
			}

			$dir = $this->prefixes[$prefix];
		}

		$file = "{$dir}/{$name}.{$this->suffix}";

		if (!is_file($file)) {
			throw new RuntimeException("Template not found: {$file}");
		}

		return $file;
	}
}
