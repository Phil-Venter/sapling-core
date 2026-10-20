<?php

namespace Sapling\Attributes;

/**
 * Makes the class a command, as in #[Command('notes:prune', 'Delete old notes')].
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Command
{
	public function __construct(public string $name, public string $description = '') {}
}
