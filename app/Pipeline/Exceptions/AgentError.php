<?php

namespace App\Pipeline\Exceptions;

use RuntimeException;

/**
 * Claude Code exited non-zero, produced no result event, or reported an error
 * such as a rate limit or authentication failure. Ends the run as an error.
 */
class AgentError extends RuntimeException {}
