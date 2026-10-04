<?php

namespace App\Pipeline\Reporters;

use App\Models\RunEvent;
use Illuminate\Support\Str;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * One-line terminal rendering of an event, shared by the live run output and
 * pipeline:show.
 */
class EventFormatter
{
    /**
     * Events that are stored but hidden unless verbose output is requested.
     */
    public function isNoise(RunEvent $event): bool
    {
        return match (true) {
            $event->type === 'system.init' => false,
            str_starts_with($event->type, 'system.') => true,
            $event->type === 'assistant.thinking' => $event->summary === null,
            $event->type === 'rate_limit' => str_starts_with((string) $event->summary, 'allowed'),
            $event->type === 'tool_result' => str_starts_with((string) $event->summary, 'ok: ') || str_starts_with((string) $event->summary, '↳ ok: '),
            default => false,
        };
    }

    /**
     * Presentation category of an event, shared by the terminal and the web UI.
     */
    public function kind(RunEvent $event): string
    {
        $summary = (string) $event->summary;

        return match ($event->type) {
            'pipeline.phase' => 'phase',
            'pipeline.error' => 'error',
            'pipeline.warning' => 'warning',
            'pipeline.command' => 'command',
            'check.result' => str_contains($summary, ' passed ') ? 'check-passed' : 'check-failed',
            'tool_use' => 'tool-use',
            'tool_result' => str_contains($summary, 'error: ') ? 'tool-error' : 'tool-ok',
            'assistant.text' => 'text',
            'assistant.thinking' => 'thinking',
            'result' => str_starts_with($summary, 'success') ? 'result-ok' : 'result-error',
            default => 'other',
        };
    }

    public function format(RunEvent $event, int $width = 160): string
    {
        $summary = Str::limit((string) ($event->summary ?? ''), max(40, $width - 32));

        [$color, $label] = match ($this->kind($event)) {
            'phase' => ['magenta', '▶'],
            'error', 'check-failed', 'tool-error', 'result-error' => ['red', $this->glyph($event)],
            'warning' => ['yellow', '!'],
            'check-passed', 'result-ok' => ['green', $this->glyph($event)],
            'tool-use' => ['cyan', '→'],
            'text' => ['white', '💬'],
            default => ['gray', $this->glyph($event)],
        };

        if ($event->type === 'pipeline.command') {
            $summary = ltrim($summary, '$ ');
        }

        return sprintf(
            '<fg=gray>%s %4d</> <fg=blue>%-8s</> <fg=%s>%s %s</>%s',
            $event->occurred_at->format('H:i:s'),
            $event->sequence,
            $event->phase->value,
            $color,
            $label,
            OutputFormatter::escape($summary),
            $event->payload_path ? ' <fg=gray>[payload op schijf]</>' : '',
        );
    }

    public function glyph(RunEvent $event): string
    {
        return match ($this->kind($event)) {
            'phase' => '▶',
            'error' => '✖',
            'warning' => '!',
            'command' => '$',
            'check-passed' => '✓',
            'check-failed' => '✗',
            'tool-use' => '→',
            'tool-ok', 'tool-error' => '←',
            'text' => '💬',
            'thinking' => '…',
            'result-ok', 'result-error' => '■',
            default => '·',
        };
    }
}
