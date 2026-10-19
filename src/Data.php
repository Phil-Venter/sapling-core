<?php

namespace Sapling;

/**
 * Converts between PHP arrays and JSON, forms and XML.
 *
 * In XML, each key is an element, a list repeats its element, @name is an attribute and #text is the text.
 */
final class Data
{
	/**
	 * JSON, with slashes and Unicode left as they are.
	 *
	 * @throws \JsonException When the data can't be encoded.
	 */
	public static function toJson(mixed $data): string
	{
		return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Decodes JSON, with objects as arrays.
	 *
	 * @throws \JsonException When the JSON is invalid.
	 */
	public static function fromJson(string $json): mixed
	{
		return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
	}

	/**
	 * A URL-encoded form body, the same format as a query string.
	 *
	 * @param array<array-key, mixed> $data
	 */
	public static function toForm(array $data): string
	{
		return http_build_query($data);
	}

	/**
	 * Decodes a URL-encoded form body.
	 *
	 * @return array<array-key, mixed>
	 */
	public static function fromForm(string $form): array
	{
		$data = [];
		parse_str($form, $data);

		return $data;
	}

	/**
	 * An XML document, with the data inside a root element.
	 *
	 * @param array<array-key, mixed> $data
	 */
	public static function toXml(array $data, string $root = 'root'): string
	{
		if ($data !== [] && array_is_list($data)) {
			throw new \InvalidArgumentException('toXml() needs keys, not a list, for its one root element');
		}

		$writer = new \XMLWriter();
		$writer->openMemory();
		$writer->startDocument('1.0', 'UTF-8');

		self::writeElement($writer, $root, $data);

		$writer->endDocument();

		return $writer->outputMemory();
	}

	/**
	 * Decodes XML into arrays, or a string when the root only holds text.
	 *
	 * @return array<string, mixed>|string
	 * @throws \RuntimeException When the XML is invalid.
	 */
	public static function fromXml(string $xml): array|string
	{
		$previous = libxml_use_internal_errors(true);
		$element = simplexml_load_string($xml, options: LIBXML_NONET | LIBXML_NOCDATA);
		$error = libxml_get_last_error();
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($element === false && $error !== false) {
			throw new \RuntimeException('Invalid XML: ' . trim($error->message));
		}

		if ($element === false) {
			throw new \RuntimeException('Invalid XML: nothing to parse');
		}

		return self::readElement($element);
	}

	/**
	 * Decodes a body by its Content-Type, as JSON, XML or a form. An empty body gives null, and any other type gives
	 * the body as it is.
	 *
	 * @throws \JsonException When a JSON body is invalid.
	 * @throws \RuntimeException When an XML body is invalid.
	 */
	public static function decode(string $body, string $contentType): mixed
	{
		if (trim($body) === '') {
			return null;
		}

		// "application/vnd.api+json; charset=utf-8" becomes json.
		$mime = strtolower(trim(explode(';', $contentType)[0]));
		$format = preg_replace('#^.*[/+]#', '', $mime);

		return match ($format) {
			'json' => self::fromJson($body),
			'xml' => self::fromXml($body),
			'x-www-form-urlencoded' => self::fromForm($body),
			default => $body,
		};
	}

	private static function writeElement(\XMLWriter $writer, string $name, mixed $value): void
	{
		if (is_array($value) && $value !== [] && array_is_list($value)) {
			foreach ($value as $item) {
				self::writeElement($writer, $name, $item);
			}

			return;
		}

		if (!is_array($value)) {
			$value = ['#text' => $value];
		}

		$writer->startElement($name);

		foreach ($value as $key => $child) {
			$key = (string) $key;

			if (str_starts_with($key, '@')) {
				$writer->writeAttribute(substr($key, 1), self::text($child));
			}
		}

		foreach ($value as $key => $child) {
			$key = (string) $key;

			if (str_starts_with($key, '@')) {
				continue;
			}

			if ($key !== '#text') {
				self::writeElement($writer, $key, $child);
				continue;
			}

			if ($child !== null) {
				$writer->text(self::text($child));
			}
		}

		$writer->endElement();
	}

	private static function text(mixed $value): string
	{
		if ($value === true) {
			return 'true';
		}

		if ($value === false) {
			return 'false';
		}

		return (string) $value;
	}

	/** @return array<string, mixed>|string */
	private static function readElement(\SimpleXMLElement $element): array|string
	{
		$data = [];

		foreach ($element->attributes() ?? [] as $name => $value) {
			$data["@{$name}"] = (string) $value;
		}

		$prefixes = array_keys($element->getNamespaces(true) + ['' => '']);

		foreach ($prefixes as $prefix) {
			$children = $element->children($prefix, true) ?? [];
			$counts = [];

			/** @var \SimpleXMLElement $child */
			foreach ($children as $child) {
				$name = $child->getName();
				$counts[$name] = ($counts[$name] ?? 0) + 1;
			}

			/** @var \SimpleXMLElement $child */
			foreach ($children as $name => $child) {
				$key = $name;

				if ($prefix !== '') {
					$key = "{$prefix}:{$name}";
				}

				if (($counts[$name] ?? 0) > 1) {
					$data[$key][] = self::readElement($child);
					continue;
				}

				$data[$key] = self::readElement($child);
			}
		}

		$text = trim((string) $element);

		if ($data === []) {
			return $text;
		}

		if ($text !== '') {
			$data['#text'] = $text;
		}

		return $data;
	}
}
