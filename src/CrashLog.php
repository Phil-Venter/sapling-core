<?php

namespace Sapling;

/**
 * Writes a crash to PHP's error log as one line, with the line of your own code that led to it.
 *
 * @internal
 */
final readonly class CrashLog
{
	public function __construct(private string $root) {}

	public function write(string $what, \Throwable $e): void
	{
		$class = $e::class;
		$message = str_replace(["\r", "\n"], ' ', $e->getMessage());
		$where = "{$e->getFile()}:{$e->getLine()}";
		$appLine = $this->firstAppLine($e);

		if ($appLine !== '') {
			$where .= " from {$appLine}";
		}

		error_log("{$what} failed: {$class}: {$message} in {$where}");
	}

	private function firstAppLine(\Throwable $e): string
	{
		if ($this->inApp($e->getFile())) {
			return '';
		}

		/** @var array{file?: string, line?: int} $frame */
		foreach ($e->getTrace() as $frame) {
			$file = $frame['file'] ?? '';
			if ($this->inApp($file)) {
				return "{$file}:" . ($frame['line'] ?? 0);
			}
		}

		return '';
	}

	private function inApp(string $file): bool
	{
		return str_starts_with($file, "{$this->root}/") && !str_starts_with($file, "{$this->root}/vendor/");
	}
}
