<?php

namespace Sapling\Http;

use RuntimeException;
use Throwable;

/**
 * Becomes a response with its status, message and headers. abort() throws one.
 */
final class HttpException extends RuntimeException
{
	private const array PHRASES = [
		400 => 'Bad Request',
		401 => 'Unauthorized',
		403 => 'Forbidden',
		404 => 'Not Found',
		405 => 'Method Not Allowed',
		409 => 'Conflict',
		413 => 'Content Too Large',
		422 => 'Unprocessable Content',
		429 => 'Too Many Requests',
		500 => 'Internal Server Error',
		503 => 'Service Unavailable',
	];

	/**
	 * Without a message, the standard phrase for the status, such as Not Found.
	 *
	 * @param array<string, string> $headers
	 */
	public function __construct(
		public readonly int $status,
		string $message = '',
		public readonly array $headers = [],
		?Throwable $previous = null,
	) {
		if ($message === '') {
			$message = self::PHRASES[$status] ?? 'Error';
		}

		parent::__construct($message, $status, $previous);
	}
}
