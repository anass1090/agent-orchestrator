<?php

namespace App\Pipeline;

use App\Models\Run;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Starts an Artisan pipeline command detached from the web request, so a run
 * outlives the request and behaves exactly like one started from the CLI.
 * Its terminal output is appended to the run's console.log.
 */
class BackgroundLauncher
{
    /**
     * @param  list<string>  $arguments
     */
    public function launch(Run $run, array $arguments): void
    {
        $log = $run->storagePath('console.log');
        File::ensureDirectoryExists(dirname($log));

        $command = collect([
            config('pipeline.php_binary'),
            base_path('artisan'), ...$arguments,
            '--no-ansi',
            '--no-interaction',
        ])->map(fn (string $part) => escapeshellarg($part))->implode(' ');

        Process::path(base_path())
            ->timeout(10)
            ->run(sprintf('setsid %s >> %s 2>&1 < /dev/null &', $command, escapeshellarg($log)))
            ->throw();
    }
}
