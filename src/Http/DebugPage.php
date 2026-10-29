<?php

namespace Sapling\Http;

/**
 * The page shown for a crash in debug mode.
 *
 * @internal
 */
final class DebugPage
{
	/**
	 * The code around the error, and a trace that folds the frames outside your app.
	 */
	public static function render(\Throwable $e, string $root): string
	{
		$root = rtrim($root, '/\\');
		$title = self::e($e::class);
		$sections = self::exceptionHtml($e, $root, '');
		$previous = $e->getPrevious();
		while ($previous !== null) {
			$sections .= self::exceptionHtml($previous, $root, 'Caused by ');
			$previous = $previous->getPrevious();
		}

		return <<<HTML
			<!doctype html>
			<meta charset="utf-8">
			<title>{$title}</title>
			<style>
			:root {
				color-scheme: light dark;
				--text: #222; --page: #fff; --code: #f5f5f5; --here: #fdd; --dim: #999; --red: #b00;
			}
			@media (prefers-color-scheme: dark) {
				:root { --text: #ddd; --page: #1b1b1b; --code: #262626; --here: #4a2424; --dim: #888; --red: #f77; }
			}
			body { font: 15px/1.5 system-ui, sans-serif; color: var(--text); background: var(--page); }
			body { max-width: 72rem; margin: 2rem auto; padding: 0 1rem; }
			h1 { font-size: 1rem; color: var(--red); margin: 2.5rem 0 0; }
			.message { font-size: 1.4rem; margin: 0.25rem 0 0.5rem; }
			pre, .trace { font: 13px/1.6 ui-monospace, monospace; }
			pre { background: var(--code); padding: 0.75rem 0; overflow-x: auto; }
			pre span { display: block; padding: 0 1rem; }
			pre .here { background: var(--here); }
			.vendor { color: var(--dim); }
			summary { cursor: pointer; }
			</style>
			{$sections}
			HTML;
	}

	private static function exceptionHtml(\Throwable $e, string $root, string $label): string
	{
		$class = self::e($label . $e::class);
		$message = self::e($e->getMessage());
		$where = self::e(self::relative($e->getFile(), $root) . ':' . $e->getLine());
		$code = self::excerpt($e->getFile(), $e->getLine());
		$trace = self::trace($e, $root);
		return <<<HTML
			<h1>{$class}</h1>
			<p class="message">{$message}</p>
			<p>{$where}</p>
			{$code}
			{$trace}

			HTML;
	}

	private static function excerpt(string $file, int $line): string
	{
		if (!is_readable($file)) {
			return '';
		}

		$lines = file($file, FILE_IGNORE_NEW_LINES);
		if ($lines === false) {
			return '';
		}

		$html = '';
		for ($number = max(1, $line - 5); $number <= min(count($lines), $line + 5); $number++) {
			$text = self::e(sprintf('%4d  %s', $number, $lines[$number - 1] ?? ''));

			$class = '';
			if ($number === $line) {
				$class = ' class="here"';
			}

			$html .= "<span{$class}>{$text}</span>";
		}

		return "<pre>{$html}</pre>";
	}

	private static function trace(\Throwable $e, string $root): string
	{
		$html = '';
		$hidden = [];
		/** @var array{function: string, line?: int, file?: string, class?: string, type?: string} $frame */
		foreach (array_values($e->getTrace()) as $index => $frame) {
			$line = self::frame($index, $frame, $root);
			if (self::fromApp($frame, $root)) {
				$html .= self::fold($hidden) . "<div>{$line}</div>";
				$hidden = [];
				continue;
			}

			$hidden[] = $line;
		}

		$html .= self::fold($hidden);
		return "<div class=\"trace\">{$html}</div>";
	}

	/** @param array{function: string, line?: int, file?: string, class?: string, type?: string} $frame */
	private static function frame(int $index, array $frame, string $root): string
	{
		$location = self::relative($frame['file'] ?? '', $root) . ':' . ($frame['line'] ?? '');
		$call = ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'];
		$call = str_replace("{$root}/", '', $call);
		return self::e("#{$index}  {$location}  {$call}()");
	}

	/** @param array{function: string, line?: int, file?: string, class?: string, type?: string} $frame */
	private static function fromApp(array $frame, string $root): bool
	{
		$files = [$frame['file'] ?? ''];
		$class = $frame['class'] ?? '';
		$match = [];
		if (class_exists($class, false)) {
			$files[] = (string) new \ReflectionClass($class)->getFileName();
		}

		if (preg_match('/^\{closure:(.+):\d+\}$/', $frame['function'], $match) === 1) {
			$files[] = $match[1] ?? '';
		}

		foreach ($files as $file) {
			if (str_starts_with($file, "{$root}/") && !str_starts_with($file, "{$root}/vendor/")) {
				return true;
			}
		}

		return false;
	}

	/** @param list<string> $lines */
	private static function fold(array $lines): string
	{
		$count = count($lines);
		if ($count === 0) {
			return '';
		}

		if ($count === 1) {
			$line = $lines[0] ?? '';
			return "<div class=\"vendor\">{$line}</div>";
		}

		$inner = '<div>' . implode('</div><div>', $lines) . '</div>';
		return "<details class=\"vendor\"><summary>{$count} frames</summary>{$inner}</details>";
	}

	private static function relative(string $file, string $root): string
	{
		if (str_starts_with($file, "{$root}/")) {
			return substr($file, strlen($root) + 1);
		}

		return $file;
	}

	private static function e(string $text): string
	{
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
