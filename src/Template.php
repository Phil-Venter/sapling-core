<?php

namespace Sapling;

use BackedEnum;
use LogicException;
use RuntimeException;
use Throwable;
use TypeError;

/**
 * Renders plain PHP templates, with layouts and sections.
 */
final class Template
{
	/** @var array<string, mixed> */
	private array $shared = [];

	/** @var array<string, string> */
	private array $sections = [];

	/** @var list<string> */
	private array $openSections = [];

	private ?string $layout = null;

	private int $depth = 0;

	public function __construct(private string $dir) {}

	/**
	 * Makes a value available to every template rendered after this.
	 */
	public function share(string $key, mixed $value): void
	{
		$this->shared[$key] = $value;
	}

	/**
	 * Renders {$template}.php with $data as its variables, inside its layout when it calls layout().
	 *
	 * @param array<string, mixed> $data
	 * @throws RuntimeException When the template file is missing.
	 * @throws Throwable Whatever the template throws.
	 */
	public function render(string $template, array $data = []): string
	{
		$file = "{$this->dir}/{$template}.php";

		if (!is_file($file)) {
			throw new RuntimeException("Template not found: {$file}");
		}

		$this->depth++;

		try {
			return $this->renderFile($file, $template, $data + $this->shared);
		} finally {
			$this->depth--;

			// Nothing carries over to the next page.
			if ($this->depth === 0) {
				$this->sections = [];
				$this->openSections = [];
				$this->layout = null;
			}
		}
	}

	/**
	 * Wraps this template in another, which prints it with yield('content'). Call it from a template.
	 */
	public function layout(string $template): void
	{
		$this->layout = $template;
	}

	/**
	 * Sets a section, as in section('title', 'Notes'), or starts one that end() closes, as in section('aside').
	 * Call it from a template.
	 */
	public function section(string $name, mixed $content = null): void
	{
		if (func_num_args() === 1) {
			$this->openSections[] = $name;
			ob_start();

			return;
		}

		if ($content instanceof BackedEnum) {
			$content = $content->value;
		}

		if (!is_string($content) && !is_int($content) && !is_float($content)) {
			$type = get_debug_type($content);
			throw new TypeError("Section {$name} needs a string, number or backed enum, got {$type}");
		}

		$this->sections[$name] = (string) $content;
	}

	/**
	 * Closes the section that section() started.
	 */
	public function end(): void
	{
		if ($this->openSections === []) {
			throw new LogicException('end() without an open section()');
		}

		$name = array_pop($this->openSections);
		$this->sections[$name] = (string) ob_get_clean();
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
	private function renderFile(string $file, string $template, array $data): string
	{
		$outerLayout = $this->layout;
		$this->layout = null;
		$level = ob_get_level();
		$opened = count($this->openSections);
		$run =
			/** @param array<string, mixed> $__data */
			function (string $__file, array $__data): void {
				extract($__data, EXTR_SKIP);
				require $__file;
			};

		ob_start();

		try {
			$run($file, $data);

			if (count($this->openSections) > $opened) {
				$name = $this->openSections[$opened] ?? '';
				throw new LogicException("Section {$name} in {$template} has no end()");
			}
		} catch (Throwable $e) {
			while (ob_get_level() > $level) {
				ob_end_clean();
			}

			throw $e;
		}

		$content = (string) ob_get_clean();

		/** @var string|null $layout */
		$layout = $this->layout;
		$this->layout = $outerLayout;

		if ($layout === null) {
			return $content;
		}

		$this->sections['content'] = $content;

		return $this->render($layout, $data);
	}
}
