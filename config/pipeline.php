<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Claude Code
    |--------------------------------------------------------------------------
    |
    | How the headless agent is invoked. A null model uses the CLI default;
    | the model that actually ran is recorded on the run from the init event.
    |
    */

    'claude' => [
        'binary' => env('PIPELINE_CLAUDE_BINARY', 'claude'),
        'model' => env('PIPELINE_MODEL'),
        'max_budget_usd' => env('PIPELINE_MAX_BUDGET_USD'),

        /*
         * Ignore ~/.claude settings and hooks so every run sees the same agent
         * configuration; the target project's .claude/settings.json still applies.
         * Strict MCP skips all MCP servers, including the project's .mcp.json
         * (e.g. Boost). Turn it off to let project MCP servers load.
         */
        'setting_sources' => env('PIPELINE_SETTING_SOURCES', 'project,local'),
        'strict_mcp_config' => (bool) env('PIPELINE_STRICT_MCP', true),

        /*
         * The agent runs with --dangerously-skip-permissions. These deny rules
         * still apply in that mode (verified with Claude Code 2.1.283).
         * Pushing is done by the pipeline itself, never by the agent.
         */
        'disallowed_tools' => [
            'Bash(git push:*)',
            'Bash(docker:*)',
            'Bash(docker-compose:*)',
            'Bash(sudo:*)',
            'Bash(su:*)',
            'Bash(ssh:*)',
            'Bash(scp:*)',
            'Bash(rm -rf /:*)',
            'Bash(rm -rf ~:*)',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Subprocess environment
    |--------------------------------------------------------------------------
    |
    | Every external command gets the parent environment minus these keys,
    | minus every key from the orchestrator's own .env (so DB_DATABASE=pipeline
    | never leaks into a target project's tests), plus the project overrides.
    |
    */

    'unset_env' => [
        'ANTHROPIC_API_KEY',
        'CLAUDECODE',
        'CLAUDE_CODE_ENTRYPOINT',
    ],

    'default_env_overrides' => [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
        'DB_URL' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeouts (seconds)
    |--------------------------------------------------------------------------
    */

    'timeouts' => [
        'setup' => (int) env('PIPELINE_TIMEOUT_SETUP', 600),
        'plan' => (int) env('PIPELINE_TIMEOUT_PLAN', 900),
        'build' => (int) env('PIPELINE_TIMEOUT_BUILD', 1800),
        'fix' => (int) env('PIPELINE_TIMEOUT_FIX', 900),
        'check' => (int) env('PIPELINE_TIMEOUT_CHECK', 600),
        'git' => (int) env('PIPELINE_TIMEOUT_GIT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Checks
    |--------------------------------------------------------------------------
    |
    | Run in this order; the first failure stops the chain. The first element
    | of each command is checked for existence before running, so a missing
    | tool is recorded as a failed check rather than silently skipped.
    |
    */

    'checks' => [
        'pint' => ['./vendor/bin/pint', '--test'],
        'phpstan' => ['./vendor/bin/phpstan', 'analyse', '--no-progress', '--no-ansi'],
        'tests' => ['php', 'artisan', 'test', '--no-ansi'],
    ],

    'max_fix_attempts' => (int) env('PIPELINE_MAX_FIX_ATTEMPTS', 3),

    /*
     * How many characters of the failing check output are sent to the agent.
     * The full output is always kept on disk.
     */
    'fix_output_chars' => 12000,

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Worktrees default to a sibling directory of the target project: same
    | filesystem, and outside this repo so the agent never picks up the
    | orchestrator's own CLAUDE.md from a parent directory.
    |
    */

    'worktree_path' => env('PIPELINE_WORKTREE_PATH'),

    'storage_path' => env('PIPELINE_STORAGE_PATH', storage_path('app/runs')),

    /*
     * Event payloads larger than this (bytes of JSON) are written to disk in
     * full; the database row keeps a copy with long strings truncated.
     */
    'payload_max_bytes' => 16384,

    'payload_string_preview' => 500,

    'branch_prefix' => 'pipeline/',

    /*
     * PHP binary used when the web UI starts a run in the background.
     */
    'php_binary' => env('PIPELINE_PHP_BINARY', PHP_BINARY),

    'commit_author' => [
        'name' => env('PIPELINE_GIT_NAME'),
        'email' => env('PIPELINE_GIT_EMAIL'),
    ],

];
