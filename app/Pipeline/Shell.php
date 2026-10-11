<?php

namespace App\Pipeline;

use App\Models\Run;
use App\Models\RunStep;
use App\Pipeline\Exceptions\CommandFailed;
use Illuminate\Contracts\Process\ProcessResult;

/**
 * Runs a pipeline housekeeping command (git, composer, cp) and records it as a
 * "pipeline.command" event.
 */
class Shell
{
    public function __construct(
        protected CommandRunner $commands,
        protected EventRecorder $recorder,
    ) {}

    /**
     * @param  list<string>  $command
     *
     * @throws CommandFailed
     */
    public function run(Run $run, RunStep $step, array $command, string $cwd, string $timeout = 'git', bool $mustSucceed = true): ProcessResult
    {
        $started = microtime(true);
        $result = $this->commands->run($command, $cwd, (int) config("pipeline.timeouts.{$timeout}"), $run->project);
        $seconds = microtime(true) - $started;

        $this->recorder->record($run, $step->phase, $step, 'pipeline.command', null,
            sprintf('$ %s → exit %d (%.1fs)', $this->display($command), $result->exitCode(), $seconds),
            [
                'command' => $command,
                'cwd' => $cwd,
                'exit_code' => $result->exitCode(),
                'output' => mb_substr($result->output(), -4000),
                'error_output' => mb_substr($result->errorOutput(), -4000),
            ],
        );

        if ($mustSucceed && ! $result->successful()) {
            throw new CommandFailed(sprintf('`%s` failed with exit %d: %s', $this->display($command), $result->exitCode(), trim(mb_substr($result->errorOutput() ?: $result->output(), -1000))));
        }

        return $result;
    }

    /**
     * Like run(), but for a command that carries a credential as one of its
     * arguments: the real command and its output never reach the recorded
     * event, only the given safe description and the exit code.
     *
     * @param  list<string>  $command
     *
     * @throws CommandFailed
     */
    public function runRedacted(Run $run, RunStep $step, array $command, string $description, string $cwd, string $timeout = 'git'): ProcessResult
    {
        $result = $this->commands->run($command, $cwd, (int) config("pipeline.timeouts.{$timeout}"), $run->project);

        $this->recorder->record($run, $step->phase, $step, 'pipeline.command', null,
            sprintf('$ %s → exit %d', $description, $result->exitCode()),
        );

        if (! $result->successful()) {
            throw new CommandFailed(sprintf('`%s` failed with exit %d', $description, $result->exitCode()));
        }

        return $result;
    }

    /**
     * @param  list<string>  $command
     */
    protected function display(array $command): string
    {
        return implode(' ', array_map(fn (string $part) => preg_match('/^[\w.\/:=@+-]+$/', $part) ? $part : escapeshellarg($part), $command));
    }
}
