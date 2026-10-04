<?php

namespace App\Pipeline;

use App\Models\Project;
use App\Pipeline\Exceptions\PipelineAborted;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Single entry point for every external command, so the environment is always
 * sanitised and a running process can be stopped when the pipeline is aborted.
 */
#[Singleton]
class CommandRunner
{
    protected ?InvokedProcess $current = null;

    protected bool $aborted = false;

    public function __construct(protected SubprocessEnvironment $environment) {}

    /**
     * @param  list<string>  $command
     * @param  (callable(string, string): void)|null  $onOutput
     */
    public function run(array $command, string $cwd, int $timeout, ?Project $project = null, ?callable $onOutput = null): ProcessResult
    {
        if ($this->aborted) {
            throw new PipelineAborted;
        }

        $this->current = Process::path($cwd)
            ->env($this->environment->for($project))
            ->timeout($timeout)
            ->start($command, $onOutput);

        try {
            $result = $this->current->wait();
        } finally {
            $this->current = null;
        }

        // Set by a signal handler while the process was running.
        if ($this->isAborted()) {
            throw new PipelineAborted;
        }

        return $result;
    }

    /**
     * Stop the running process and make the pipeline stop at its next command.
     */
    public function abort(): void
    {
        $this->aborted = true;
        $this->current?->signal(SIGTERM);
    }

    /**
     * Allow commands again, used for the commit, push and cleanup after an abort.
     */
    public function resume(): void
    {
        $this->aborted = false;
    }

    /**
     * @phpstan-impure
     */
    public function isAborted(): bool
    {
        return $this->aborted;
    }
}
