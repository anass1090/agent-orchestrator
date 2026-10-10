<?php

namespace App\Console\Commands;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Pipeline\EventRecorder;
use App\Pipeline\Pipeline;
use App\Pipeline\Reporters\ConsoleReporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[
    Signature(
        '
            pipeline:phase
            {run : Het run-id}
            {phase : setup, plan, build, checks, fixloop, commit, push, cleanup of finish}
        '
    )]
#[Description('Voer één fase van een bestaande run uit, om te debuggen')]
class PipelinePhaseCommand extends Command
{
    /** @var list<string> */
    protected array $phases = ['setup', 'plan', 'build', 'checks', 'fixloop', 'commit', 'push', 'cleanup', 'finish'];

    /**
     * Execute the console command.
     */
    public function handle(Pipeline $pipeline, EventRecorder $recorder): int
    {
        $run = $this->resolveRun();
        $phase = $this->argument('phase');

        if ($run === null || ! $this->validatePhase($phase) || ! $this->ensureWorktree($run, $phase)) {
            return self::FAILURE;
        }

        $recorder->setReporter(new ConsoleReporter($this->output));
        $this->trap([SIGINT, SIGTERM], fn () => $pipeline->abort());

        $run->forceFill(
            ['status' => RunStatus::Running, 'started_at' => $run->started_at ?? now(), 'pid' => getmypid()])->save();

        $this->runPhase($pipeline, $run, $phase);

        $this->newLine();
        $this->call('pipeline:show', ['run' => $run->id, '--no-events' => true]);

        return self::SUCCESS;
    }

    protected function resolveRun(): ?Run
    {
        $run = Run::with('project')->find($this->argument('run'));

        if ($run === null) {
            $this->error("Run [{$this->argument('run')}] bestaat niet.");
        }

        return $run;
    }

    protected function validatePhase(string $phase): bool
    {
        if (in_array($phase, $this->phases, true)) {
            return true;
        }

        $this->error('Onbekende fase. Kies uit: '.implode(', ', $this->phases));

        return false;
    }

    protected function ensureWorktree(Run $run, string $phase): bool
    {
        $needsWorktree = ! in_array($phase, ['setup', 'cleanup', 'finish'], true);

        if (! $needsWorktree || ($run->worktree_path !== null && is_dir($run->worktree_path))) {
            return true;
        }

        $this->error('Deze run heeft geen worktree. Draai eerst: php artisan pipeline:phase '.$run->id.' setup');

        return false;
    }

    protected function runPhase(Pipeline $pipeline, Run $run, string $phase): void
    {
        try {
            match ($phase) {
                'setup' => $pipeline->setup($run),
                'plan' => $pipeline->plan($run),
                'build' => $pipeline->build($run),
                'checks' => $this->reportChecks($pipeline, $run),
                'fixloop' => $pipeline->fixLoop($run),
                'commit' => $pipeline->commit($run),
                'push' => $pipeline->push($run),
                'cleanup' => $pipeline->cleanup($run),
                'finish' => $pipeline->finish($run),
            };
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());
        } finally {
            if ($run->status === RunStatus::Running) {
                $run->forceFill(['status' => RunStatus::Paused, 'stop_reason' => "manual_{$phase}"])->save();
            }

            $pipeline->refreshTotals($run);
        }
    }

    protected function reportChecks(Pipeline $pipeline, Run $run): void
    {
        $outcome = $pipeline->checks($run, $run->fix_attempts);

        $outcome->passed
            ? $this->components->info('Alle controles geslaagd.')
            : $this->components->error("Controle {$outcome->failedCheck->value} gefaald.");
    }
}
