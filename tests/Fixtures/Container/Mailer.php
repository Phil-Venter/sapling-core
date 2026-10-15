<?php

namespace Sapling\Tests\Fixtures\Container;

final class Mailer
{
	public function __construct(public Clock $clock, public string $from = 'noreply@example.com') {}
}
