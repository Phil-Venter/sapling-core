<?php

namespace Sapling\Cli;

/**
 * A command run the wrong way, such as --days 7 for --days=7. runCommand() prints only its message and exits with 1.
 */
final class UsageException extends \RuntimeException {}
