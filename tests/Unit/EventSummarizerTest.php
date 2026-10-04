<?php

use App\Pipeline\EventSummarizer;

it('summarises tool calls with paths relative to the worktree', function (string $tool, array $input, string $expected) {
    expect((new EventSummarizer('/work/tree'))->describeToolUse($tool, $input))->toBe($expected);
})->with([
    ['Read', ['file_path' => '/work/tree/app/Models/Invoice.php'], 'Read app/Models/Invoice.php'],
    ['Read', ['file_path' => '/work/tree/a.php', 'offset' => 10, 'limit' => 20], 'Read a.php (10-30)'],
    ['Edit', ['file_path' => '/work/tree/routes/web.php'], 'Edit routes/web.php'],
    ['Bash', ['command' => "php artisan test\n  --filter=Foo"], 'Bash: php artisan test --filter=Foo'],
    ['Grep', ['pattern' => 'Invoice', 'path' => '/work/tree/app'], 'Grep: Invoice in app'],
    ['Task', ['description' => 'Explore models'], 'Agent: Explore models'],
    ['Mystery', ['thing' => 'x'], 'Mystery: x'],
]);

it('splits an assistant message into one event per content block', function () {
    $events = (new EventSummarizer)->summarize([
        'type' => 'assistant',
        'message' => ['id' => 'msg_1', 'content' => [
            ['type' => 'text', 'text' => 'Ik lees eerst het model.'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Read', 'input' => ['file_path' => 'app/User.php']],
        ]],
    ]);

    expect($events)->toHaveCount(2)
        ->and($events[0]->type)->toBe('assistant.text')
        ->and($events[1]->type)->toBe('tool_use')
        ->and($events[1]->tool)->toBe('Read')
        ->and($events[1]->summary)->toBe('Read app/User.php');
});

it('links tool results to the tool that produced them', function () {
    $summarizer = new EventSummarizer;
    $summarizer->summarize(['type' => 'assistant', 'message' => ['content' => [
        ['type' => 'tool_use', 'id' => 'toolu_9', 'name' => 'Bash', 'input' => ['command' => 'docker ps']],
    ]]]);

    [$event] = $summarizer->summarize(['type' => 'user', 'message' => ['content' => [
        ['type' => 'tool_result', 'tool_use_id' => 'toolu_9', 'is_error' => true, 'content' => 'Permission denied'],
    ]]]);

    expect($event->tool)->toBe('Bash')
        ->and($event->summary)->toBe('error: Permission denied');
});

it('marks events from subagents', function () {
    [$event] = (new EventSummarizer)->summarize(['type' => 'assistant', 'parent_tool_use_id' => 'toolu_1', 'message' => ['content' => [
        ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'Glob', 'input' => ['pattern' => '**/*.php']],
    ]]]);

    expect($event->summary)->toBe('↳ Glob: **/*.php');
});
