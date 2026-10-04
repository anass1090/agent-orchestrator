<?php

namespace App\Pipeline\Reporters;

use App\Models\RunEvent;

interface Reporter
{
    public function event(RunEvent $event): void;
}
