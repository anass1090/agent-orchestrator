<?php

namespace App\Pipeline;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Splits Claude Code stream-json messages into one event per content block and
 * gives each a short human-readable summary, e.g. "Read app/Models/Invoice.php".
 *
 * Stateful per agent session: tool results are matched to the tool_use that
 * produced them.
 */
class EventSummarizer
{
    /** @var array<string, string> */
    protected array $toolNames = [];

    public function __construct(protected string $worktreePath = '') {}

    /**
     * @param  array<string, mixed>  $message
     * @return list<StreamEvent>
     */
    public function summarize(array $message): array
    {
        $type = (string) $message['type'];
        $nested = ! empty($message['parent_tool_use_id']) ? '↳ ' : '';

        return match ($type) {
            'system' => [$this->system($message)],
            'assistant' => $this->assistant($message, $nested),
            'user' => $this->user($message, $nested),
            'result' => [$this->result($message)],
            'rate_limit_event' => [$this->rateLimit($message)],
            'stream.invalid' => [new StreamEvent('stream.invalid', null, $this->line((string) ($message['raw'] ?? '')), $message)],
            default => [new StreamEvent($type, null, null, $message)],
        };
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function describeToolUse(string $tool, array $input): string
    {
        $path = fn (string $key): string => $this->relative((string) ($input[$key] ?? ''));

        return match ($tool) {
            'Read' => 'Read '.$path('file_path').$this->readRange($input),
            'Write' => 'Write '.$path('file_path'),
            'Edit', 'MultiEdit' => 'Edit '.$path('file_path'),
            'NotebookEdit' => 'Edit '.$path('notebook_path'),
            'Bash' => 'Bash: '.$this->line((string) ($input['command'] ?? '')),
            'Glob' => 'Glob: '.($input['pattern'] ?? '').(isset($input['path']) ? ' in '.$path('path') : ''),
            'Grep' => 'Grep: '.($input['pattern'] ?? '').(isset($input['path']) ? ' in '.$path('path') : ''),
            'LS' => 'List '.$path('path'),
            'Task', 'Agent' => 'Agent: '.$this->line((string) ($input['description'] ?? $input['prompt'] ?? '')),
            'TodoWrite' => 'Todo: '.count((array) ($input['todos'] ?? [])).' items',
            'WebFetch' => 'Fetch '.($input['url'] ?? ''),
            'WebSearch' => 'Search: '.($input['query'] ?? ''),
            'Skill' => 'Skill: '.($input['skill'] ?? ''),
            default => $tool.$this->firstScalar($input),
        };
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function system(array $message): StreamEvent
    {
        $subtype = (string) ($message['subtype'] ?? 'unknown');

        $summary = $subtype === 'init'
            ? sprintf('Session %s · %s · %d tools', $message['session_id'] ?? '?', $message['model'] ?? '?', count((array) ($message['tools'] ?? [])))
            : null;

        return new StreamEvent('system.'.$subtype, null, $summary, $message);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<StreamEvent>
     */
    protected function assistant(array $message, string $nested): array
    {
        $events = [];
        $meta = Arr::only($message, ['session_id', 'uuid', 'parent_tool_use_id']) + [
            'message_id' => $message['message']['id'] ?? null,
            'usage' => $message['message']['usage'] ?? null,
        ];

        foreach ((array) ($message['message']['content'] ?? []) as $block) {
            $blockType = $block['type'] ?? 'unknown';
            $payload = $meta + ['block' => $block];

            $events[] = match ($blockType) {
                'text' => new StreamEvent('assistant.text', null, $nested.$this->line((string) $block['text']), $payload),
                'thinking' => new StreamEvent('assistant.thinking', null, $this->line((string) ($block['thinking'] ?? '')) ?: null, $payload),
                'tool_use' => $this->toolUse($block, $payload, $nested),
                default => new StreamEvent('assistant.'.$blockType, null, null, $payload),
            };
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $payload
     */
    protected function toolUse(array $block, array $payload, string $nested): StreamEvent
    {
        $tool = (string) ($block['name'] ?? 'unknown');
        $this->toolNames[(string) ($block['id'] ?? '')] = $tool;

        return new StreamEvent('tool_use', $tool, $nested.$this->describeToolUse($tool, (array) ($block['input'] ?? [])), $payload);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<StreamEvent>
     */
    protected function user(array $message, string $nested): array
    {
        $content = $message['message']['content'] ?? [];
        $meta = Arr::only($message, ['session_id', 'uuid', 'parent_tool_use_id', 'tool_use_result']);

        if (is_string($content)) {
            return [new StreamEvent('user.text', null, $nested.$this->line($content), $meta + ['text' => $content])];
        }

        $events = [];

        foreach ((array) $content as $block) {
            if (($block['type'] ?? null) !== 'tool_result') {
                $events[] = new StreamEvent('user.'.($block['type'] ?? 'unknown'), null, null, $meta + ['block' => $block]);

                continue;
            }

            $tool = $this->toolNames[(string) ($block['tool_use_id'] ?? '')] ?? null;
            $isError = (bool) ($block['is_error'] ?? false);
            $text = $this->resultText($block['content'] ?? '');

            $events[] = new StreamEvent(
                'tool_result',
                $tool,
                $nested.($isError ? 'error: ' : 'ok: ').$this->line($text),
                $meta + ['block' => $block],
            );
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function result(array $message): StreamEvent
    {
        $summary = sprintf(
            '%s · %d turns · $%.4f · %.1fs',
            $message['subtype'] ?? '?',
            $message['num_turns'] ?? 0,
            $message['total_cost_usd'] ?? 0,
            ($message['duration_ms'] ?? 0) / 1000,
        );

        if (! empty($message['is_error'])) {
            $summary .= ' · '.$this->line((string) ($message['result'] ?? ''));
        }

        return new StreamEvent('result', null, $summary, $message);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function rateLimit(array $message): StreamEvent
    {
        $info = (array) ($message['rate_limit_info'] ?? []);

        $summary = sprintf('%s (%s)', $info['status'] ?? '?', $info['rateLimitType'] ?? '?');

        return new StreamEvent('rate_limit', null, $summary, $message);
    }

    protected function resultText(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        return collect((array) $content)
            ->map(fn ($part) => is_array($part) ? ($part['text'] ?? '['.($part['type'] ?? '?').']') : (string) $part)
            ->implode(' ');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function readRange(array $input): string
    {
        if (! isset($input['offset']) && ! isset($input['limit'])) {
            return '';
        }

        $from = (int) ($input['offset'] ?? 1);

        return isset($input['limit']) ? " ({$from}-".($from + (int) $input['limit']).')' : " (from {$from})";
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function firstScalar(array $input): string
    {
        foreach ($input as $value) {
            if (is_scalar($value) && $value !== '') {
                return ': '.$this->line((string) $value);
            }
        }

        return '';
    }

    protected function relative(string $path): string
    {
        if ($this->worktreePath !== '' && str_starts_with($path, rtrim($this->worktreePath, '/').'/')) {
            return substr($path, strlen(rtrim($this->worktreePath, '/')) + 1);
        }

        return $path;
    }

    protected function line(string $text, int $limit = 200): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return Str::limit($text, $limit);
    }
}
