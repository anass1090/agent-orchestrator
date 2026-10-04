<?php

namespace App\Enums;

enum RunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Error = 'error';
    case Aborted = 'aborted';
    case Paused = 'paused';

    public function isFinished(): bool
    {
        return ! in_array($this, [self::Pending, self::Running, self::Paused], true);
    }
}
