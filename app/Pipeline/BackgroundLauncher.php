<?php

namespace App\Pipeline;

use App\Models\Run;
use Illuminate\Support\Facades\Process;

/**
 * Starts an Artisan pipeline command detached from the web request, so a run
 * outlives the request and behaves exactly like one started from the CLI.
 * Its terminal output is discarded; everything that matters is already
 * recorded as run events.
 */
class BackgroundLauncher
{
    /**
     * @param  list<string>  $arguments
     */
    public function launch(Run $run, array $arguments): void
    {
        $command = collect([
            config('pipeline.php_binary'),
            base_path('artisan'), ...$arguments,
            '--no-ansi',
            '--no-interaction',
        ])->map(fn (string $part) => escapeshellarg($part))->implode(' ');

        Process::path(base_path())
            ->timeout(10)
            ->run(sprintf('setsid %s > /dev/null 2>&1 < /dev/null &', $command))
            ->throw();
    }
}
