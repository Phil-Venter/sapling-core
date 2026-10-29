<?php

namespace Sapling;

/**
 * Converts PHP arrays to and from JSON and forms.
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
	 * Objects become arrays.
	 *
	 * @throws \JsonException When the JSON is invalid.
	 */
	public static function fromJson(string $json): mixed
	{
		return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
	}

	/** @param array<array-key, mixed> $data */
	public static function toForm(array $data): string
	{
		return http_build_query($data);
	}

	/** @return array<array-key, mixed> */
	public static function fromForm(string $form): array
	{
		$data = [];
		parse_str($form, $data);
		return $data;
	}

	/**
	 * Decodes a body by its Content-Type, as JSON or a form. An empty body gives null, and any other type gives the body as it is.
	 *
	 * @throws \JsonException When a JSON body is invalid.
	 */
	public static function decode(string $body, string $contentType): mixed
	{
		if (trim($body) === '') {
			return null;
		}

		$mime = strtolower(trim(explode(';', $contentType)[0]));
		$format = preg_replace('#^.*[/+]#', '', $mime);
		return match ($format) {
			'json' => self::fromJson($body),
			'x-www-form-urlencoded' => self::fromForm($body),
			default => $body,
		};
	}
}
