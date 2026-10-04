<?php

namespace App\Enums;

enum StepStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Error = 'error';
    case Skipped = 'skipped';
}
