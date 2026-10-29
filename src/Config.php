<?php

namespace Sapling;

/**
 * The settings returned by config.php.
 */
final readonly class Config
{
	/**
	 * Settings from an array, for tests. Use load() to read config.php and .env.
	 *
	 * @param array<string, mixed> $values
	 */
	public function __construct(private array $values) {}

	/**
	 * Runs config.php with $env, which holds .env and the real environment variables.
	 *
	 * @throws \RuntimeException When a line in .env isn't KEY=value.
	 */
	public static function load(string $configFile, string $envFile): self
	{
		$env = getenv() + self::readEnv($envFile);
		$load =
			/** @param array<string, string> $env */
			static fn(string $__file, array $env): mixed => require $__file;
		return new self($load($configFile, $env));
	}

	/**
	 * @return array<string, string>
	 * @throws \RuntimeException
	 */
	private static function readEnv(string $file): array
	{
		if (!is_file($file)) {
			return [];
		}

		$env = [];
		$lines = file($file);
		if ($lines === false) {
			throw new \RuntimeException("Cannot read {$file}");
		}

		foreach ($lines as $index => $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#') {
				continue;
			}

			$equals = strpos($line, '=');
			if ($equals === false) {
				$number = $index + 1;
				throw new \RuntimeException("Expected KEY=value in {$file} on line {$number}");
			}

			$key = trim(substr($line, 0, $equals));
			$env[$key] = self::envValue(trim(substr($line, $equals + 1)));
		}

		return $env;
	}

	private static function envValue(string $value): string
	{
		$quoted = [];
		if (preg_match('/^(["\'])(.*?)\1(\s+#.*)?$/', $value, $quoted) === 1) {
			return $quoted[2] ?? '';
		}

		return trim((string) preg_replace('/(^|\s)#.*$/', '', $value));
	}

	/**
	 * Without a default, a missing key throws a LogicException.
	 */
	public function get(string $key, mixed $default = null): mixed
	{
		if (array_key_exists($key, $this->values)) {
			return $this->values[$key];
		}

		if (func_num_args() > 1) {
			return $default;
		}

		throw new \LogicException("Missing config key: {$key}");
	}
}
