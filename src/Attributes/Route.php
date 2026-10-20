<?php

namespace Sapling\Attributes;

/**
 * Makes the class answer a method and path, as in #[Route('GET', '/notes/:id')]. A class can have more than one.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class Route
{
	/**
	 * Slashes at either end of the path don't matter.
	 */
	public function __construct(public string $method, public string $path) {}
}
