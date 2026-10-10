<?php

namespace App\Pipeline;

use App\Enums\Phase;
use App\Models\Run;
use App\Models\RunEvent;
use App\Models\RunStep;
use App\Pipeline\Reporters\NullReporter;
use App\Pipeline\Reporters\Reporter;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\File;

/**
 * Persists run events with a gapless per-run sequence number and forwards them
 * to the live reporter. Oversized payloads go to disk in full; the row keeps a
 * copy with long strings truncated so it stays queryable.
 */
#[Singleton]
class EventRecorder
{
    /** @var array<int, int> */
    protected array $sequences = [];

    protected Reporter $reporter;

    public function __construct()
    {
        $this->reporter = new NullReporter;
    }

    public function setReporter(Reporter $reporter): void
    {
        $this->reporter = $reporter;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(Run $run, Phase $phase, ?RunStep $step, string $type, ?string $tool = null, ?string $summary = null, array $payload = []): RunEvent
    {
        $sequence = $this->nextSequence($run);
        [$payload, $payloadPath] = $this->resolvePayload($run, $sequence, $payload);

        $event = $run->events()->create([
            'run_step_id' => $step?->id,
            'phase' => $phase,
            'sequence' => $sequence,
            'type' => $type,
            'tool' => $tool,
            'summary' => $summary,
            'payload' => $payload === [] ? null : $payload,
            'payload_path' => $payloadPath,
            'occurred_at' => now(),
        ]);

        $this->reporter->event($event);

        return $event;
    }

    /**
     * Oversized payloads go to disk in full; the row keeps a truncated
     * preview so it stays queryable.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    protected function resolvePayload(Run $run, int $sequence, array $payload): array
    {
        $payload = $this->sanitize($payload);
        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if (strlen($json) <= config('pipeline.payload_max_bytes')) {
            return [$payload, null];
        }

        $payloadPath = $run->storagePath("payloads/{$sequence}.json");
        File::ensureDirectoryExists(dirname($payloadPath));
        File::put($payloadPath, $json);

        $payload = $this->truncate($payload, (int) config('pipeline.payload_string_preview'));
        $payload['_truncated'] = true;

        return [$payload, $payloadPath];
    }

    protected function nextSequence(Run $run): int
    {
        if (! isset($this->sequences[$run->id])) {
            $this->sequences[$run->id] = (int) $run->events()->max('sequence');
        }

        return ++$this->sequences[$run->id];
    }

    /**
     * Postgres jsonb rejects NUL characters and invalid UTF-8.
     */
    protected function sanitize(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_replace("\u{0000}", '', mb_scrub($value, 'UTF-8'));
        }

        if (is_array($value)) {
            return array_map($this->sanitize(...), $value);
        }

        return $value;
    }

    protected function truncate(mixed $value, int $limit): mixed
    {
        if (is_string($value) && strlen($value) > $limit) {
            return mb_strcut($value, 0, $limit).sprintf('… [%d bytes truncated]', strlen($value) - $limit);
        }

        if (is_array($value)) {
            return array_map(fn ($item) => $this->truncate($item, $limit), $value);
        }

        return $value;
    }
}
