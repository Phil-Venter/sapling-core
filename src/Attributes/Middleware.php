<?php

namespace Sapling\Attributes;

use Attribute;

/**
 * Runs middleware around this class's routes. Repeat it for more, with the first one outermost.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Middleware
{
	/** @param class-string $class */
	public function __construct(public string $class) {}
}
