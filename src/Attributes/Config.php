<?php

namespace Sapling\Attributes;

/**
 * Fills a constructor parameter from a config key, as in #[Config('app_key')]. The parameter's default is used when the key is missing.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Config
{
	public function __construct(public string $key) {}
}
