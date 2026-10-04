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

#[Signature('pipeline:cleanup {--run= : Alleen deze run}')]
#[Description('Commit, push en verwijder achtergebleven worktrees, bijvoorbeeld na een kill -9')]
class PipelineCleanupCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(Pipeline $pipeline, EventRecorder $recorder): int
    {
        $recorder->setReporter(new ConsoleReporter($this->output));

        $runs = Run::with('project')
            ->whereNotNull('worktree_path')
            ->when($this->option('run'), fn ($query, $id) => $query->whereKey($id))
            ->get()
            ->reject(fn (Run $run) => $run->status === RunStatus::Running && $run->hasLiveProcess());

        if ($runs->isEmpty()) {
            $this->components->info('Geen achtergebleven worktrees.');

            return self::SUCCESS;
        }

        foreach ($runs as $run) {
            $this->components->task("Run #{$run->id}: {$run->worktree_path}", function () use ($pipeline, $run) {
                if ($run->status === RunStatus::Running) {
                    $run->forceFill(['status' => RunStatus::Error, 'stop_reason' => 'crash', 'error' => 'Process '.$run->pid.' is niet meer actief'])->save();
                }

                $pipeline->finish($run);
                $pipeline->refreshTotals($run);
            });
        }

        return self::SUCCESS;
    }
}
