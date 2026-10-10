<?php

namespace App\Console\Commands;

use App\Enums\Phase;
use App\Models\Run;
use App\Models\RunStep;
use App\Pipeline\Reporters\EventFormatter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Terminal;

#[Signature('pipeline:show
    {run : Het run-id}
    {--phase= : Toon alleen events van deze fase}
    {--no-events : Toon alleen de fases}
    {--all : Toon ook ruis-events (thinking tokens, geslaagde tool results)}')]
#[Description('Toon de fases van een run met status en duur, en de events op volgorde')]
class PipelineShowCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(EventFormatter $formatter): int
    {
        $run = Run::with('project')->find($this->argument('run'));

        if ($run === null) {
            $this->error("Run [{$this->argument('run')}] bestaat niet.");

            return self::FAILURE;
        }

        $this->renderSummary($run);
        $this->newLine();
        $this->renderSteps($run);

        if ($this->option('no-events')) {
            return self::SUCCESS;
        }

        $this->renderEvents($run, $formatter);

        return self::SUCCESS;
    }

    protected function renderSummary(Run $run): void
    {
        $this->components->twoColumnDetail('<fg=white;options=bold>Run #'.$run->id.'</>', $this->status($run->status->value));
        $this->components->twoColumnDetail('Project', $run->project->name);
        $this->components->twoColumnDetail('Prompt', str($run->prompt)->squish()->limit(80)->toString());
        $this->components->twoColumnDetail('Branch', ($run->branch ?? '-').($run->pushed ? ' (gepusht)' : ''));
        $this->components->twoColumnDetail('Model', $run->model ?? '-');
        $this->components->twoColumnDetail('Stopreden', $run->stop_reason ?? '-');
        $this->components->twoColumnDetail('Gefaalde controle', $run->failed_check ?? '-');
        $this->components->twoColumnDetail('Fixpogingen', (string) $run->fix_attempts);
        $this->components->twoColumnDetail('Tokens (in / out / cache r / cache w)', sprintf('%s / %s / %s / %s',
            number_format($run->input_tokens), number_format($run->output_tokens), number_format($run->cache_read_tokens), number_format($run->cache_creation_tokens)));
        $this->components->twoColumnDetail('Kosten', '$'.number_format((float) $run->cost_usd, 4));
        $this->components->twoColumnDetail('Duur', $this->duration($run->duration_ms));

        if ($run->error) {
            $this->components->twoColumnDetail('Fout', '<fg=red>'.str($run->error)->limit(120).'</>');
        }
    }

    protected function renderSteps(Run $run): void
    {
        $this->table(
            ['Fase', 'Poging', 'Status', 'Start', 'Duur', 'Exit', 'Tokens in/out', 'Kosten'],
            $run->steps()->orderBy('id')->get()->map(fn (RunStep $step) => [
                $step->phase->value,
                $step->attempt,
                $this->status($step->status->value),
                $step->started_at->format('H:i:s'),
                $this->duration($step->duration_ms),
                $step->exit_code ?? '-',
                $step->phase->isAgent() ? number_format($step->input_tokens + $step->cache_read_tokens + $step->cache_creation_tokens).' / '.number_format($step->output_tokens) : '',
                $step->phase->isAgent() ? '$'.number_format((float) $step->cost_usd, 4) : '',
            ]),
        );
    }

    protected function renderEvents(Run $run, EventFormatter $formatter): void
    {
        $width = (new Terminal)->getWidth();
        $events = $run->events()->orderBy('sequence');

        if ($phase = $this->option('phase')) {
            $events->where('phase', Phase::from($phase));
        }

        foreach ($events->lazy() as $event) {
            if (! $this->option('all') && $formatter->isNoise($event)) {
                continue;
            }

            $this->line($formatter->format($event, $width));
        }

        $this->newLine();
        $this->line('<fg=gray>Bestanden: '.$run->storagePath().'</>');
    }

    protected function status(string $status): string
    {
        $color = match ($status) {
            'succeeded' => 'green',
            'failed', 'error' => 'red',
            'aborted', 'paused', 'skipped' => 'yellow',
            default => 'blue',
        };

        return "<fg={$color}>{$status}</>";
    }

    protected function duration(?int $milliseconds): string
    {
        if ($milliseconds === null) {
            return '-';
        }

        $seconds = intdiv($milliseconds, 1000);

        return $seconds >= 60 ? sprintf('%dm%02ds', intdiv($seconds, 60), $seconds % 60) : sprintf('%.1fs', $milliseconds / 1000);
    }
}
