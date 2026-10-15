<?php

namespace Sapling\Attributes;

use Attribute;

/**
 * Makes the class answer a method and path, as in #[Route('GET', '/notes/:id')]. A class can have more than one.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Route
{
	/**
	 * The method, such as GET, and the path, such as /notes/:id. Slashes at either end don't matter.
	 */
	public function __construct(public string $method, public string $path) {}
}
