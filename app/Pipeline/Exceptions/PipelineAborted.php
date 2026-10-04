<?php

namespace App\Pipeline\Exceptions;

use RuntimeException;

class PipelineAborted extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Pipeline aborted by signal.');
    }
}
