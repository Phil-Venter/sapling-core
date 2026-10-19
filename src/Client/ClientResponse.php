<?php

namespace Sapling\Client;

use JsonException;
use RuntimeException;
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
	 * A reply built by hand, for tests or from a cache. HttpClient builds the real ones.
	 *
	 * @param array<string, string|list<string>> $headers
	 */
	public function __construct(public string $body, public int $status, array $headers)
	{
		$this->headers = array_change_key_case($headers);
	}

	/**
	 * A header, ignoring the case of its name, as in header('Retry-After')->int(60). A header sent more than once is
	 * a list, read with array().
	 */
	public function header(string $name): Value
	{
		return new Value($this->headers[strtolower($name)] ?? null);
	}

	/**
	 * A field from the body, decoded by its Content-Type as JSON, XML or a form, as in data('name')->string(). More
	 * keys read deeper, and no key gives the whole body. An empty body gives an empty Value, and any other type gives
	 * the body as it is.
	 *
	 * @throws JsonException When a JSON body is invalid.
	 * @throws RuntimeException When an XML body is invalid.
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
