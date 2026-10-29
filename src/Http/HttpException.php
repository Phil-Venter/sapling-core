<?php

namespace Sapling\Http;

/**
 * Becomes a response with its status, message and headers. abort() throws one.
 */
final class HttpException extends \RuntimeException
{
	private const array PHRASES = [
		400 => 'Bad Request',
		401 => 'Unauthorized',
		402 => 'Payment Required',
		403 => 'Forbidden',
		404 => 'Not Found',
		405 => 'Method Not Allowed',
		409 => 'Conflict',
		410 => 'Gone',
		413 => 'Content Too Large',
		415 => 'Unsupported Media Type',
		422 => 'Unprocessable Content',
		429 => 'Too Many Requests',
		500 => 'Internal Server Error',
		501 => 'Not Implemented',
		502 => 'Bad Gateway',
		503 => 'Service Unavailable',
		504 => 'Gateway Timeout',
	];

	/**
	 * Without a message, the standard phrase for the status, such as Not Found.
	 *
	 * @param array<string, string> $headers
	 */
	public function __construct(
		public readonly int   $status,
		string                $message = '',
		public readonly array $headers = [],
		?\Throwable           $previous = null,
	) {
		if ($message === '') {
			$message = self::PHRASES[$status] ?? 'Error';
		}

		parent::__construct($message, $status, $previous);
	}
}
