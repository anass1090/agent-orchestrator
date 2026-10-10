<?php

namespace App\Pipeline;

use App\Enums\Phase;
use App\Enums\RunStatus;
use App\Enums\StepStatus;
use App\Models\Project;
use App\Models\Run;
use App\Models\RunStep;
use App\Pipeline\Exceptions\AgentError;
use App\Pipeline\Exceptions\PhaseFailed;
use App\Pipeline\Exceptions\PipelineAborted;
use Closure;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Feature request → plan → build → checks with a bounded fix loop, without
 * human intervention. Every phase is public so it can be run on its own.
 */
class Pipeline
{
    public function __construct(
        protected Worktree $worktree,
        protected ClaudeRunner $claude,
        protected CommandRunner $commands,
        protected Shell $shell,
        protected EventRecorder $recorder,
        protected PromptRepository $prompts,
        protected FailureFingerprint $fingerprints,
    ) {}

    public function start(Project $project, string $prompt): Run
    {
        return $project->runs()->create([
            'prompt' => $prompt,
            'status' => RunStatus::Pending,
        ]);
    }

    /**
     * Run all phases. The worktree is committed, pushed and removed afterwards
     * whatever happens, unless it is kept for debugging.
     */
    public function execute(Run $run, ?Phase $until = null, bool $keepWorktree = false): Run
    {
        $this->markRunning($run);

        try {
            $this->runPhases($run, $until);
        } catch (Throwable $e) {
            $this->markFailure($run, $e);
        } finally {
            $this->finalize($run, $until, $keepWorktree);
        }

        return $run;
    }

    protected function markRunning(Run $run): void
    {
        $run->forceFill([
            'status' => RunStatus::Running,
            'started_at' => now(),
            'pid' => getmypid(),
        ])->save();
    }

    protected function runPhases(Run $run, ?Phase $until): void
    {
        $this->setup($run);
        $this->plan($run);

        if ($until !== Phase::Plan) {
            $this->build($run);

            if ($until !== Phase::Build) {
                $this->fixLoop($run);
            }
        }

        $this->pauseIfStoppingEarly($run, $until);
    }

    protected function pauseIfStoppingEarly(Run $run, ?Phase $until): void
    {
        if ($until !== null && $run->status === RunStatus::Running) {
            $run->forceFill(['status' => RunStatus::Paused, 'stop_reason' => "stopped_after_{$until->value}"])->save();
        }
    }

    protected function finalize(Run $run, ?Phase $until, bool $keepWorktree): void
    {
        $this->commands->resume();

        if (! $keepWorktree && $until === null) {
            $this->finish($run);
        }

        $this->refreshTotals($run);
    }

    public function setup(Run $run): void
    {
        $this->step($run, Phase::Setup, 0, function (RunStep $step) use ($run) {
            if ($run->worktree_path === null || ! is_dir($run->worktree_path)) {
                $this->worktree->create($run, $step);
            }

            $this->worktree->prepare($run, $step);
        });
    }

    public function plan(Run $run): void
    {
        $result = $this->agent($run, Phase::Plan, 0, $this->prompts->user(Phase::Plan, ['feature' => $run->prompt]));
        $run->forceFill(['plan_session_id' => $result->sessionId])->save();

        $path = (string) $run->worktree_path;
        $missing = array_filter(['plan.md', 'aannames.md'], fn (string $file) => ! is_file("{$path}/{$file}"));

        foreach (['plan.md', 'aannames.md'] as $file) {
            if (is_file("{$path}/{$file}")) {
                File::ensureDirectoryExists($run->storagePath());
                File::copy("{$path}/{$file}", $run->storagePath($file));
            }
        }

        if ($missing !== []) {
            throw new PhaseFailed('Plan phase did not produce: '.implode(', ', $missing), 'plan_missing');
        }

        $this->warnAboutCodeWrittenDuringPlan($run);
    }

    public function build(Run $run): void
    {
        $result = $this->agent($run, Phase::Build, 0, $this->prompts->user(Phase::Build, ['feature' => $run->prompt]));
        $run->forceFill(['build_session_id' => $result->sessionId])->save();
    }

    /**
     * Run the checks in order and stop at the first failure.
     */
    public function checks(Run $run, int $attempt): CheckOutcome
    {
        foreach (Phase::checks() as $check) {
            $outcome = $this->step($run, $check, $attempt, fn (RunStep $step) => $this->runCheck($run, $step, $check));

            if (! $outcome->passed) {
                return $outcome;
            }
        }

        return CheckOutcome::passed();
    }

    /**
     * Send the failing check output back to the agent, resuming the build
     * session so it keeps the context of what it built.
     */
    public function fix(Run $run, int $attempt, CheckOutcome $failure): void
    {
        $prompt = $this->prompts->user(Phase::Fix, [
            'feature' => $run->prompt,
            'check' => $failure->failedCheck->value,
            'command' => implode(' ', $failure->command),
            'output' => $this->excerpt($failure->output),
            'attempt' => $attempt,
            'max_attempts' => (int) config('pipeline.max_fix_attempts'),
        ]);

        $result = $this->agent($run, Phase::Fix, $attempt, $prompt, $run->build_session_id);
        $run->forceFill(['build_session_id' => $result->sessionId ?? $run->build_session_id])->save();
    }

    /**
     * Checks, then up to max_fix_attempts fixes. Stops early when a fix
     * attempt leaves exactly the same failure behind.
     */
    public function fixLoop(Run $run): CheckOutcome
    {
        $maxAttempts = (int) config('pipeline.max_fix_attempts');
        $outcome = $this->checks($run, $run->fix_attempts);

        while (! $outcome->passed) {
            if ($run->fix_attempts >= $maxAttempts) {
                return $this->concludeChecks($run, $outcome, 'max_fix_attempts');
            }

            $run->increment('fix_attempts');
            $this->fix($run, $run->fix_attempts, $outcome);

            $previous = $outcome;
            $outcome = $this->checks($run, $run->fix_attempts);

            if (! $outcome->passed && $outcome->fingerprint === $previous->fingerprint) {
                $this->recorder->record($run, Phase::Fix, null, 'pipeline.warning', null,
                    "Same {$outcome->failedCheck->value} failure as before the fix, stopping");

                return $this->concludeChecks($run, $outcome, 'repeated_failure');
            }
        }

        return $this->concludeChecks($run, $outcome, 'checks_passed');
    }

    /**
     * Commit, push and remove the worktree. Each part is isolated so a failure
     * in one never prevents the worktree from being removed.
     */
    public function finish(Run $run): void
    {
        if ($run->worktree_path !== null && is_dir($run->worktree_path)) {
            $this->attempt(fn () => $this->commit($run));
            $this->attempt(fn () => $this->push($run));
        }

        $this->attempt(fn () => $this->cleanup($run));
    }

    public function commit(Run $run): void
    {
        $this->step($run, Phase::Commit, 0, function (RunStep $step) use ($run) {
            $run->forceFill(['result_commit' => $this->worktree->commit($run, $step)])->save();
        });
    }

    public function push(Run $run): void
    {
        $this->step($run, Phase::Push, 0, function (RunStep $step) use ($run) {
            $pushed = $this->worktree->push($run, $step);
            $run->forceFill(['pushed' => $pushed])->save();

            if (! $pushed) {
                $step->status = StepStatus::Skipped;
                $step->error = 'Project has no reachable remote';
            }
        });
    }

    public function cleanup(Run $run): void
    {
        $this->step($run, Phase::Cleanup, 0, fn (RunStep $step) => $this->worktree->remove($run, $step));
    }

    public function abort(): void
    {
        $this->commands->abort();
    }

    public function refreshTotals(Run $run): void
    {
        $totals = $run->steps()
            ->selectRaw('coalesce(sum(input_tokens), 0) as input_tokens, coalesce(sum(output_tokens), 0) as output_tokens')
            ->selectRaw('coalesce(sum(cache_creation_tokens), 0) as cache_creation_tokens, coalesce(sum(cache_read_tokens), 0) as cache_read_tokens')
            ->selectRaw('coalesce(sum(cost_usd), 0) as cost_usd')
            ->toBase()
            ->first();

        $finishedAt = $run->status->isFinished() ? ($run->finished_at ?? now()) : null;

        $run->forceFill([
            'input_tokens' => (int) $totals->input_tokens,
            'output_tokens' => (int) $totals->output_tokens,
            'cache_creation_tokens' => (int) $totals->cache_creation_tokens,
            'cache_read_tokens' => (int) $totals->cache_read_tokens,
            'cost_usd' => $totals->cost_usd,
            'finished_at' => $finishedAt,
            'duration_ms' => $finishedAt && $run->started_at ? (int) $run->started_at->diffInMilliseconds($finishedAt) : null,
        ])->save();
    }

    /**
     * @template T
     *
     * @param  Closure(RunStep): T  $callback
     * @return T
     */
    protected function step(Run $run, Phase $phase, int $attempt, Closure $callback): mixed
    {
        $step = $run->steps()->create([
            'phase' => $phase,
            'attempt' => $attempt,
            'status' => StepStatus::Running,
            'started_at' => now(),
        ]);

        $this->recorder->record($run, $phase, $step, 'pipeline.phase', null, $attempt > 0 ? "{$phase->value} (attempt {$attempt})" : $phase->value);

        try {
            $result = $callback($step);

            if ($step->status === StepStatus::Running) {
                $step->status = StepStatus::Succeeded;
            }

            return $result;
        } catch (Throwable $e) {
            $step->status = $e instanceof PhaseFailed ? StepStatus::Failed : StepStatus::Error;
            $step->error = $e->getMessage();
            $this->recorder->record($run, $phase, $step, 'pipeline.error', null, $e->getMessage(), ['exception' => $e::class]);

            throw $e;
        } finally {
            $step->finished_at = now();
            $step->duration_ms = (int) $step->started_at->diffInMilliseconds($step->finished_at);
            $step->save();
        }
    }

    protected function agent(Run $run, Phase $phase, int $attempt, string $prompt, ?string $resumeSessionId = null): ClaudeResult
    {
        $system = $this->prompts->system($phase, ['max_attempts' => (int) config('pipeline.max_fix_attempts')]);

        return $this->step($run, $phase, $attempt, function (RunStep $step) use ($run, $phase, $prompt, $system, $resumeSessionId) {
            try {
                $result = $this->claude->run($run, $step, $phase, $prompt, $system, $resumeSessionId);
            } catch (ProcessTimedOutException) {
                throw new PhaseFailed(sprintf('Agent did not finish within %ds', config("pipeline.timeouts.{$phase->value}")), 'agent_timeout');
            }

            $step->fill(['exit_code' => $result->exitCode, 'session_id' => $result->sessionId, ...$result->usage()]);

            if ($result->model !== null && $run->model === null) {
                $run->forceFill(['model' => $result->model])->save();
            }

            if (! $result->succeeded()) {
                $subtype = (string) ($result->resultEvent['subtype'] ?? '');

                // Hitting the turn or budget limit is an outcome of the agent, not an infrastructure error.
                if (str_starts_with($subtype, 'error_max_')) {
                    throw new PhaseFailed($result->failureReason(), $subtype);
                }

                throw new AgentError($result->failureReason());
            }

            return $result;
        });
    }

    protected function runCheck(Run $run, RunStep $step, Phase $check): CheckOutcome
    {
        $path = (string) $run->worktree_path;
        [$command, $exitCode, $output] = $this->executeCheckCommand($run, $check, $path);

        return $this->recordCheckOutcome($run, $step, $check, $path, $command, $exitCode, $output);
    }

    /**
     * @return array{0: list<string>, 1: ?int, 2: string}
     */
    protected function executeCheckCommand(Run $run, Phase $check, string $path): array
    {
        /** @var list<string> $command */
        $command = config("pipeline.checks.{$check->value}");

        if (str_starts_with($command[0], './') && ! is_file($path.'/'.$command[0])) {
            return [$command, null, "Tool ontbreekt: {$command[0]} bestaat niet in het project."];
        }

        try {
            $result = $this->commands->run($command, $path, (int) config('pipeline.timeouts.check'), $run->project);

            return [$command, $result->exitCode(), trim($result->output()."\n".$result->errorOutput())];
        } catch (ProcessTimedOutException $e) {
            $output = trim($e->result->output()."\n".$e->result->errorOutput())
                ."\n\nCheck timed out after ".config('pipeline.timeouts.check').'s.';

            return [$command, null, $output];
        }
    }

    /**
     * Persist the check's log and fingerprint, update the step and record the
     * event, then build the outcome every caller acts on.
     *
     * @param  list<string>  $command
     */
    protected function recordCheckOutcome(Run $run, RunStep $step, Phase $check, string $path, array $command, ?int $exitCode, string $output): CheckOutcome
    {
        $passed = $exitCode === 0;
        $logPath = $run->storagePath("checks/{$step->attempt}-{$check->value}.log");
        File::ensureDirectoryExists(dirname($logPath));
        File::put($logPath, $output);

        $fingerprint = $passed ? null : $this->fingerprints->make($check->value, $output, $path);

        $step->fill([
            'status' => $passed ? StepStatus::Succeeded : StepStatus::Failed,
            'exit_code' => $exitCode,
            'output_path' => $logPath,
            'fingerprint' => $fingerprint,
        ]);

        $this->recorder->record($run, $check, $step, 'check.result', null,
            sprintf('%s %s (exit %s)', $check->value, $passed ? 'passed' : 'failed', $exitCode ?? '-'),
            ['command' => $command, 'exit_code' => $exitCode, 'output' => mb_substr($output, -4000)],
        );

        return new CheckOutcome($passed, $passed ? null : $check, $output, $fingerprint, $command);
    }

    protected function concludeChecks(Run $run, CheckOutcome $outcome, string $stopReason): CheckOutcome
    {
        $run->forceFill([
            'status' => $outcome->passed ? RunStatus::Succeeded : RunStatus::Failed,
            'failed_check' => $outcome->failedCheck?->value,
            'stop_reason' => $stopReason,
        ])->save();

        return $outcome;
    }

    protected function markFailure(Run $run, Throwable $e): void
    {
        [$status, $reason] = match (true) {
            $e instanceof PipelineAborted => [RunStatus::Aborted, 'aborted'],
            $e instanceof PhaseFailed => [RunStatus::Failed, $e->stopReason],
            $e instanceof AgentError => [RunStatus::Error, 'agent_error'],
            default => [RunStatus::Error, 'exception'],
        };

        $run->forceFill(['status' => $status, 'stop_reason' => $reason, 'error' => $e->getMessage()])->save();

        if (! $e instanceof PhaseFailed && ! $e instanceof AgentError && ! $e instanceof PipelineAborted) {
            report($e);
        }
    }

    protected function warnAboutCodeWrittenDuringPlan(Run $run): void
    {
        $status = $this->commands->run(['git', 'status', '--porcelain', '--untracked-files=all'], (string) $run->worktree_path, (int) config('pipeline.timeouts.git'), $run->project);

        $changed = collect(explode("\n", trim($status->output())))
            ->filter()
            ->map(fn (string $line) => trim(substr($line, 3)))
            ->reject(fn (string $file) => in_array($file, ['plan.md', 'aannames.md'], true))
            ->values();

        if ($changed->isNotEmpty()) {
            $this->recorder->record($run, Phase::Plan, null, 'pipeline.warning', null,
                'Plan phase changed '.$changed->count().' file(s) besides plan.md/aannames.md',
                ['files' => $changed->all()],
            );
        }
    }

    /**
     * Keep the start (first errors) and the end (summary) of long check output.
     */
    protected function excerpt(string $output): string
    {
        $limit = (int) config('pipeline.fix_output_chars');

        if (mb_strlen($output) <= $limit) {
            return $output;
        }

        $head = (int) ($limit / 3);

        return mb_substr($output, 0, $head)
            ."\n\n[… ".(mb_strlen($output) - $limit)." tekens weggelaten …]\n\n"
            .mb_substr($output, -($limit - $head));
    }

    protected function attempt(Closure $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
