<?php

namespace App\Pipeline\Reporters;

use App\Models\RunEvent;

class NullReporter implements Reporter
{
    public function event(RunEvent $event): void {}
}
