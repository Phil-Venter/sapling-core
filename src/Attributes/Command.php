<?php

namespace Sapling\Attributes;

use Attribute;

/**
 * Makes the class a command, as in #[Command('notes:prune', 'Delete old notes')].
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Command
{
	/**
	 * The name, such as notes:prune, and the description commands:list shows.
	 */
	public function __construct(public string $name, public string $description = '') {}
}
