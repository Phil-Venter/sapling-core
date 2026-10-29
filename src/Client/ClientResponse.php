<?php

namespace Sapling\Client;

use Sapling\Data;
use Sapling\Value;

/**
 * A reply from another service, as HttpClient returns it.
 */
final readonly class ClientResponse
{
	/** @var array<string, string|list<string>> Lowercase names, and a header sent more than once is a list. */
	public array $headers;

	/**
	 * A reply built by hand, for tests or from a cache.
	 *
	 * @param array<string, string|list<string>> $headers
	 */
	public function __construct(public string $body, public int $status, array $headers)
	{
		$this->headers = array_change_key_case($headers);
	}

	/**
	 * The name is case-insensitive. A header sent more than once is a list, read with rawArray().
	 */
	public function header(string $name): Value
	{
		return new Value($this->headers[strtolower($name)] ?? null);
	}

	/**
	 * The body, decoded as JSON, XML or a form by its Content-Type. Any other body stays a string. More keys read
	 * deeper, as Value::get() does.
	 *
	 * @throws \JsonException When a JSON body is invalid.
	 * @throws \RuntimeException When an XML body is invalid.
	 */
	public function data(string|int ...$keys): Value
	{
		// A Content-Type sent twice is a list, and the first one counts.
		$type = $this->header('content-type');
		$first = $type->get(0)->string();
		$contentType = $type->string($first);
		return new Value(Data::decode($this->body, $contentType))->get(...$keys);
	}
}
