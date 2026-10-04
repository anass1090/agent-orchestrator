<?php

namespace App\Pipeline\Reporters;

use App\Models\RunEvent;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Terminal;

class ConsoleReporter implements Reporter
{
    public function __construct(
        protected OutputStyle $output,
        protected EventFormatter $formatter = new EventFormatter,
    ) {}

    public function event(RunEvent $event): void
    {
        if (! $this->output->isVerbose() && $this->formatter->isNoise($event)) {
            return;
        }

        $this->output->writeln($this->formatter->format($event, (new Terminal)->getWidth()));
    }
}
