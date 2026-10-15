<?php

namespace Sapling\Http;

use Sapling\Value;

/**
 * PHP's session, with flash values. It starts the first time it's used.
 */
final class Session
{
	private const string FLASH = 'sapling.flash';

	private bool $started = false;

	/** @var array<string, mixed> */
	private array $flash = [];

	/**
	 * $lifetime is the seconds the cookie and an idle session last, and 0 leaves both to php.ini.
	 */
	public function __construct(private int $lifetime = 0) {}

	/**
	 * A value from the session, as in get('user_id')->int().
	 */
	public function get(string $key): Value
	{
		$this->start();

		return new Value($_SESSION[$key] ?? null);
	}

	/**
	 * Stores a value in the session.
	 */
	public function set(string $key, mixed $value): void
	{
		$this->start();
		$_SESSION[$key] = $value;
	}

	/**
	 * Removes a value from the session.
	 */
	public function forget(string $key): void
	{
		$this->start();
		unset($_SESSION[$key]);
	}

	/**
	 * Stores a value for the next request only.
	 */
	public function setFlash(string $key, mixed $value): void
	{
		$this->start();
		$_SESSION[self::FLASH][$key] = $value;
	}

	/**
	 * A value stored with setFlash() during the previous request, as in getFlash('notice')->string().
	 */
	public function getFlash(string $key): Value
	{
		$this->start();

		return new Value($this->flash[$key] ?? null);
	}

	/**
	 * Gives the session a new id and keeps its data. Call it after signing someone in.
	 */
	public function regenerate(): void
	{
		$this->start();
		session_regenerate_id(true);
	}

	/**
	 * Ends the session and clears its cookie. The next use starts a new one.
	 */
	public function destroy(): void
	{
		$this->start();
		$_SESSION = [];

		$cookie = session_get_cookie_params();
		unset($cookie['lifetime']);
		setcookie((string) session_name(), '', ['expires' => 1, ...$cookie]);

		session_destroy();
		$this->started = false;
		$this->flash = [];
	}

	private function start(): void
	{
		if ($this->started) {
			return;
		}

		$https = !in_array($_SERVER['HTTPS'] ?? '', ['', 'off'], true);
		$proxiedHttps = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

		$options = [
			'cookie_httponly' => true,
			'cookie_samesite' => 'Lax',
			'cookie_secure' => $https || $proxiedHttps,
			'use_strict_mode' => true,
		];

		if ($this->lifetime > 0) {
			$options += ['cookie_lifetime' => $this->lifetime, 'gc_maxlifetime' => $this->lifetime];
		}

		if (session_status() !== PHP_SESSION_ACTIVE) {
			session_start($options);
		}

		$this->started = true;
		$this->flash = $_SESSION[self::FLASH] ?? [];
		unset($_SESSION[self::FLASH]);
	}
}
