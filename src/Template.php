<?php

namespace Sapling;

/**
 * Renders plain PHP templates, with partials and components.
 */
final class Template
{
	/** @var array<string, mixed> */
	private array $shared = [];

	/**
	 * The components and slots opened but not yet closed with end(), innermost last.
	 *
	 * @var list<array{kind: 'slot'|'component', name: string, data: array<string, mixed>, slots: array<string, mixed>}>
	 */
	private array $open = [];

	/**
	 * The slots of the component being rendered. The default slot is ''.
	 *
	 * @var array<string, mixed>
	 */
	private array $slots = [];

	/** @var array<string, string> */
	private array $prefixes = [];

	/**
	 * Templates are $dir/{name}.{$suffix}, such as views/notes.tmpl.php.
	 */
	public function __construct(private string $dir, private string $suffix = 'tmpl.php') {}

	/**
	 * Renders {$template}.{$suffix}. Inside a template, it prints a partial, as in render('_nav').
	 *
	 * @param array<string, mixed> $data
	 * @throws \RuntimeException When the template file is missing.
	 * @throws \Throwable Anything the template throws.
	 */
	public function render(string $template, array $data = []): string
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

			if (count($this->open) < $opened) {
				throw new \LogicException("end() in {$template} closes a component or slot it didn't open");
			}

			// Before ob_get_clean(), which would otherwise read the unclosed one's buffer.
			$unclosed = $this->open[$opened] ?? null;

			if ($unclosed !== null) {
				$kind = ucfirst($unclosed['kind']);
				throw new \LogicException("{$kind} {$unclosed['name']} in {$template} has no end()");
			}
		} catch (\Throwable $e) {
			while (ob_get_level() > $level) {
				ob_end_clean();
			}

			array_splice($this->open, $opened);

			throw $e;
		}

		return (string) ob_get_clean();
	}

	/**
	 * Starts a component, as in component('_card', ['title' => 'Notes']). What's printed until end() is its default
	 * slot.
	 *
	 * @param array<string, mixed> $data
	 */
	public function component(string $template, array $data = []): void
	{
		$this->open[] = ['kind' => 'component', 'name' => $template, 'data' => $data, 'slots' => []];
		ob_start();
	}

	/**
	 * Sets a named slot of the component it's in, as in slot('title', 'Notes'), or starts one that end() closes, as
	 * in slot('footer').
	 */
	public function slot(string $name, mixed $content = null): void
	{
		$component = $this->openComponent();

		if (func_num_args() === 1) {
			$this->open[] = ['kind' => 'slot', 'name' => $name, 'data' => [], 'slots' => []];
			ob_start();

			return;
		}

		$this->open[$component]['slots'][$name] = $content;
	}

	/**
	 * Closes the last slot() or component(). Closing a component prints it.
	 *
	 * @throws \RuntimeException When a component's template file is missing.
	 * @throws \Throwable Anything a component's template throws.
	 */
	public function end(): void
	{
		$open = array_pop($this->open);

		if ($open === null) {
			throw new \LogicException('end() without an open slot() or component()');
		}

		$content = (string) ob_get_clean();

		if ($open['kind'] === 'slot') {
			$component = $this->openComponent();
			$this->open[$component]['slots'][$open['name']] = $content;

			return;
		}

		$outer = $this->slots;
		$this->slots = ['' => $content, ...$open['slots']];

		try {
			echo $this->render($open['name'], $open['data']);
		} finally {
			$this->slots = $outer;
		}
	}

	/**
	 * A slot of the component being rendered, or $default when it wasn't set. yield() is the default slot, and
	 * yield('title') a named one.
	 */
	public function yield(string $name = '', mixed $default = null): mixed
	{
		return $this->slots[$name] ?? $default;
	}

	/**
	 * Makes a value available to every template rendered after this.
	 */
	public function share(string $key, mixed $value): void
	{
		$this->shared[$key] = $value;
	}

	/**
	 * Lets prefix::name find templates in another folder, as in prefix('notes', __DIR__ . '/app/Notes') for
	 * render('notes::list').
	 */
	public function prefix(string $prefix, string $dir): void
	{
		$this->prefixes[$prefix] = rtrim($dir, '/\\');
	}

	/** @throws \RuntimeException */
	private function file(string $template): string
	{
		$dir = $this->dir;
		$name = $template;

		if (str_contains($template, '::')) {
			$parts = explode('::', $template, 2);
			$prefix = $parts[0];
			$name = $parts[1] ?? '';

			if (!array_key_exists($prefix, $this->prefixes)) {
				throw new \LogicException("Unknown template prefix {$prefix} in {$template}");
			}

			$dir = $this->prefixes[$prefix];
		}

		$file = "{$dir}/{$name}.{$this->suffix}";

		if (!is_file($file)) {
			throw new \RuntimeException("Template not found: {$file}");
		}

		return $file;
	}

	private function openComponent(): int
	{
		foreach (array_reverse($this->open, true) as $index => $open) {
			if ($open['kind'] === 'component') {
				return $index;
			}
		}

		throw new \LogicException('slot() outside a component()');
	}
}
