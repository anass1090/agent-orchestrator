<?php

namespace App\Console\Commands;

use App\Enums\Phase;
use App\Enums\RunStatus;
use App\Models\Project;
use App\Models\Run;
use App\Pipeline\EventRecorder;
use App\Pipeline\Pipeline;
use App\Pipeline\Reporters\ConsoleReporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('pipeline:run
    {project? : Naam van het geregistreerde project}
    {prompt? : Het featureverzoek}
    {--run= : Voer een al aangemaakte run uit (gebruikt door de web-UI)}
    {--until= : Stop na deze fase (plan of build); de worktree blijft staan}
    {--keep-worktree : Commit, push en ruim de worktree niet op na afloop}')]
#[Description('Zet een featureverzoek zonder tussenkomst om in code: plan, bouw, controles en fixloop')]
class PipelineRunCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(Pipeline $pipeline, EventRecorder $recorder): int
    {
        $until = $this->option('until') ? Phase::tryFrom($this->option('until')) : null;

        if ($this->option('until') && ! in_array($until, [Phase::Plan, Phase::Build], true)) {
            $this->error('--until accepteert alleen plan of build.');

            return self::FAILURE;
        }

        $keepWorktree = $this->option('keep-worktree') || $until !== null;
        $recorder->setReporter(new ConsoleReporter($this->output));

        $run = $this->resolveRun($pipeline);

        if ($run === null) {
            return self::FAILURE;
        }

        $this->components->info("Run #{$run->id} gestart voor {$run->project->name}");

        $this->trapAbortSignal($pipeline);
        $this->registerCrashSafetyNet($pipeline, $run, $keepWorktree);

        $pipeline->execute($run, $until, $keepWorktree);

        $this->newLine();
        $this->call('pipeline:show', ['run' => $run->id, '--no-events' => true]);

        return $run->status === RunStatus::Succeeded || $run->status === RunStatus::Paused ? self::SUCCESS : self::FAILURE;
    }

    protected function trapAbortSignal(Pipeline $pipeline): void
    {
        $this->trap([SIGINT, SIGTERM], function () use ($pipeline) {
            $this->newLine();
            $this->components->warn('Signaal ontvangen: huidige stap wordt gestopt, daarna commit, push en opruimen.');
            $pipeline->abort();
        });
    }

    /**
     * Last resort for fatal errors: never leave a worktree behind.
     */
    protected function registerCrashSafetyNet(Pipeline $pipeline, Run $run, bool $keepWorktree): void
    {
        register_shutdown_function(function () use ($pipeline, $run, $keepWorktree) {
            if ($keepWorktree) {
                return;
            }

            try {
                $run->refresh();

                if ($run->worktree_path !== null) {
                    if (! $run->status->isFinished()) {
                        $run->forceFill(['status' => RunStatus::Error, 'stop_reason' => 'crash'])->save();
                    }

                    $pipeline->finish($run);
                    $pipeline->refreshTotals($run);
                }
            } catch (Throwable) {
                // pipeline:cleanup handles whatever is still left.
            }
        });
    }

    protected function resolveRun(Pipeline $pipeline): ?Run
    {
        if ($id = $this->option('run')) {
            $run = Run::with('project')->find($id);

            if ($run === null || $run->status !== RunStatus::Pending) {
                $this->error("Run [{$id}] bestaat niet of is al gestart.");

                return null;
            }

            return $run;
        }

        if (! $this->argument('project') || ! $this->argument('prompt')) {
            $this->error('Geef een project en een prompt op, of --run=<id>.');

            return null;
        }

        $project = Project::where('name', $this->argument('project'))->first();

        if ($project === null) {
            $this->error("Project [{$this->argument('project')}] bestaat niet. Registreer het met pipeline:project.");

            return null;
        }

        return $pipeline->start($project, $this->argument('prompt'));
    }
}
