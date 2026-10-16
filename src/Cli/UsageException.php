<?php

namespace Sapling\Cli;

use RuntimeException;

/**
 * A command run the wrong way, such as --days 7 for --days=7. The command prints only the message and exits with 1,
 * while any other exception is a crash, with its trace.
 */
final class UsageException extends RuntimeException {}
