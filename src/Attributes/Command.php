<?php

namespace Sapling\Attributes;

/**
 * Makes the class a command, as in #[Command('notes:prune', 'Delete notes older than --days=30', ['days'])].
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Command
{
	/**
	 * $options names the options and flags the command takes. Any other stops it before it runs.
	 *
	 * @param list<string> $options
	 */
	public function __construct(public string $name, public string $description = '', public array $options = []) {}
}
