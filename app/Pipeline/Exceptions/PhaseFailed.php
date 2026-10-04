<?php

namespace App\Pipeline\Exceptions;

use RuntimeException;

/**
 * The agent or a pipeline step did not produce what the phase requires.
 * Ends the run as failed (a measured outcome), not as an infrastructure error.
 */
class PhaseFailed extends RuntimeException
{
    public function __construct(string $message, public readonly string $stopReason)
    {
        parent::__construct($message);
    }
}
