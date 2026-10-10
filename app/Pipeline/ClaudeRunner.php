<?php

namespace App\Pipeline;

use App\Enums\Phase;
use App\Models\Run;
use App\Models\RunStep;
use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Runs Claude Code headless with stream-json output and records every event
 * live while the process is running.
 */
class ClaudeRunner
{
    public function __construct(
        protected CommandRunner $commands,
        protected EventRecorder $recorder,
    ) {}

    public function run(Run $run, RunStep $step, Phase $phase, string $prompt, string $systemPrompt, ?string $resumeSessionId = null): ClaudeResult
    {
        $parser = new StreamJsonParser;
        $summarizer = new EventSummarizer((string) $run->worktree_path);
        $raw = $this->openRawLog($run, $phase, $step);

        $sessionId = null;
        $model = null;
        $resultEvent = null;
        $stderr = '';

        $handle = $this->messageHandler($run, $step, $phase, $summarizer, $sessionId, $model, $resultEvent);

        try {
            $result = $this->commands->run(
                $this->command($prompt, $systemPrompt, $resumeSessionId),
                (string) $run->worktree_path,
                (int) config("pipeline.timeouts.{$phase->value}"),
                $run->project,
                $this->outputHandler($parser, $handle, $raw, $stderr),
            );

            foreach ($parser->flush() as $message) {
                $handle($message);
            }
        } finally {
            fclose($raw);
            $this->persistStderr($run, $phase, $step, $stderr);
        }

        return new ClaudeResult($result->exitCode() ?? -1, $sessionId, $model, $resultEvent, $stderr);
    }

    /**
     * Open the file every raw stream-json line is appended to, for debugging.
     *
     * @return resource
     */
    protected function openRawLog(Run $run, Phase $phase, RunStep $step)
    {
        $rawPath = $run->storagePath("streams/{$phase->value}-{$step->attempt}.jsonl");
        File::ensureDirectoryExists(dirname($rawPath));
        $raw = fopen($rawPath, 'ab');

        if ($raw === false) {
            throw new RuntimeException("Cannot open [{$rawPath}] for writing.");
        }

        return $raw;
    }

    protected function persistStderr(Run $run, Phase $phase, RunStep $step, string $stderr): void
    {
        if ($stderr !== '') {
            File::put($run->storagePath("streams/{$phase->value}-{$step->attempt}.stderr"), $stderr);
        }
    }

    /**
     * Builds the callback that turns one parsed stream-json message into
     * recorded events, remembering the session metadata as it goes by.
     *
     * @param  array<string, mixed>|null  $resultEvent
     */
    protected function messageHandler(Run $run, RunStep $step, Phase $phase, EventSummarizer $summarizer, ?string &$sessionId, ?string &$model, ?array &$resultEvent): Closure
    {
        return function (array $message) use ($run, $step, $phase, $summarizer, &$sessionId, &$model, &$resultEvent): void {
            if (($message['type'] ?? null) === 'system' && ($message['subtype'] ?? null) === 'init') {
                $sessionId = $message['session_id'] ?? null;
                $model = $message['model'] ?? null;
            }

            if (($message['type'] ?? null) === 'result') {
                $resultEvent = $message;
                $sessionId = $message['session_id'] ?? $sessionId;
            }

            foreach ($summarizer->summarize($message) as $event) {
                $this->recorder->record($run, $phase, $step, $event->type, $event->tool, $event->summary, $event->payload);
            }
        };
    }

    /**
     * Builds the callback that routes a subprocess output chunk: stderr is
     * buffered, stdout is appended to the raw log and fed to the parser.
     *
     * @param  resource  $raw
     */
    protected function outputHandler(StreamJsonParser $parser, Closure $handle, $raw, string &$stderr): Closure
    {
        return function (string $type, string $output) use ($parser, $handle, $raw, &$stderr): void {
            if ($type === 'err') {
                $stderr .= $output;

                return;
            }

            fwrite($raw, $output);

            foreach ($parser->feed($output) as $message) {
                $handle($message);
            }
        };
    }

    /**
     * @return list<string>
     */
    public function command(string $prompt, string $systemPrompt, ?string $resumeSessionId = null): array
    {
        $config = config('pipeline.claude');

        $command = [
            $config['binary'],
            '--print',
            '--output-format', 'stream-json',
            '--verbose',
            '--dangerously-skip-permissions',
            '--append-system-prompt', $systemPrompt,
        ];

        if ($config['setting_sources']) {
            array_push($command, '--setting-sources', $config['setting_sources']);
        }

        if ($config['strict_mcp_config']) {
            $command[] = '--strict-mcp-config';
        }

        if ($config['model']) {
            array_push($command, '--model', $config['model']);
        }

        if ($config['max_budget_usd']) {
            array_push($command, '--max-budget-usd', (string) $config['max_budget_usd']);
        }

        if ($resumeSessionId) {
            array_push($command, '--resume', $resumeSessionId);
        }

        if ($config['disallowed_tools'] !== []) {
            $command[] = '--disallowedTools';
            array_push($command, ...$config['disallowed_tools']);
        }

        // "--" ends the variadic --disallowedTools list so the prompt is not swallowed by it.
        array_push($command, '--', $prompt);

        return $command;
    }
}
