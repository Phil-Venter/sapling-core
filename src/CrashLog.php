<?php

namespace Sapling;

use Sapling\Http\HttpException;

/**
 * Writes a crash or a 5xx HttpException to PHP's error log as one line, with the line of your own code that led to it and the exceptions that caused it.
 *
 * @internal
 */
final readonly class CrashLog
{
	public function __construct(private string $root) {}

	public function write(string $what, \Throwable $e): void
	{
		$causes = [];
		for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
			$causes[] = $this->describe($cause);
		}

		error_log("{$what} failed: " . implode(', caused by ', $causes));
	}

	private function describe(\Throwable $e): string
	{
		$kind = $e instanceof HttpException ? $e->status : $e::class;
		$message = str_replace(["\r", "\n"], ' ', $e->getMessage());
		$where = "{$e->getFile()}:{$e->getLine()}";
		$appLine = $this->firstAppLine($e);

		if ($appLine !== '') {
			$where .= " from {$appLine}";
		}

		return "{$kind}: {$message} in {$where}";
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
