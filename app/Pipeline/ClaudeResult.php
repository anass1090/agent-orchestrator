<?php

namespace App\Pipeline;

class ClaudeResult
{
    /**
     * @param  array<string, mixed>|null  $resultEvent
     */
    public function __construct(
        public readonly int $exitCode,
        public readonly ?string $sessionId,
        public readonly ?string $model,
        public readonly ?array $resultEvent,
        public readonly string $stderr,
    ) {}

    /**
     * Only trust the run when the process exited cleanly AND the stream ended
     * with a successful result event. Rate limits and auth failures exit
     * non-zero with empty or partial stdout.
     */
    public function succeeded(): bool
    {
        return $this->exitCode === 0
            && $this->resultEvent !== null
            && ($this->resultEvent['subtype'] ?? null) === 'success'
            && empty($this->resultEvent['is_error']);
    }

    public function failureReason(): string
    {
        if ($this->exitCode !== 0) {
            $detail = (string) ($this->resultEvent['result'] ?? '');

            if ($detail === '') {
                $detail = trim($this->stderr) ?: 'no output';
            }

            return sprintf('claude exited with code %d: %s', $this->exitCode, mb_substr($detail, 0, 1000));
        }

        if ($this->resultEvent === null) {
            return 'claude exited 0 but the stream contained no result event';
        }

        return sprintf('claude reported %s: %s', $this->resultEvent['subtype'] ?? 'error', mb_substr((string) ($this->resultEvent['result'] ?? ''), 0, 1000));
    }

    /**
     * @return array{input_tokens: int, output_tokens: int, cache_creation_tokens: int, cache_read_tokens: int, cost_usd: float}
     */
    public function usage(): array
    {
        $usage = (array) ($this->resultEvent['usage'] ?? []);

        return [
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'cache_creation_tokens' => (int) ($usage['cache_creation_input_tokens'] ?? 0),
            'cache_read_tokens' => (int) ($usage['cache_read_input_tokens'] ?? 0),
            'cost_usd' => (float) ($this->resultEvent['total_cost_usd'] ?? 0),
        ];
    }
}
