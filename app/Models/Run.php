<?php

namespace App\Models;

use App\Enums\RunStatus;
use Carbon\CarbonImmutable;
use Database\Factories\RunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $project_id
 * @property string $prompt
 * @property string|null $branch
 * @property RunStatus $status
 * @property string|null $failed_check
 * @property int $fix_attempts
 * @property string|null $stop_reason
 * @property string|null $error
 * @property string|null $model
 * @property string|null $worktree_path
 * @property string|null $base_commit
 * @property string|null $result_commit
 * @property bool $pushed
 * @property string|null $plan_session_id
 * @property string|null $build_session_id
 * @property int|null $pid
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_creation_tokens
 * @property int $cache_read_tokens
 * @property string $cost_usd
 * @property int|null $duration_ms
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 */
#[Fillable([
    'project_id', 'prompt', 'branch', 'status', 'failed_check', 'fix_attempts', 'stop_reason', 'error',
    'model', 'worktree_path', 'base_commit', 'result_commit', 'pushed', 'plan_session_id', 'build_session_id',
    'pid', 'input_tokens', 'output_tokens', 'cache_creation_tokens', 'cache_read_tokens', 'cost_usd',
    'duration_ms', 'started_at', 'finished_at',
])]
class Run extends Model
{
    /** @use HasFactory<RunFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'fix_attempts' => 0,
        'pushed' => false,
        'input_tokens' => 0,
        'output_tokens' => 0,
        'cache_creation_tokens' => 0,
        'cache_read_tokens' => 0,
        'cost_usd' => 0,
    ];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<RunStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(RunStep::class);
    }

    /**
     * @return HasMany<RunEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(RunEvent::class);
    }

    /**
     * Whether the pipeline process that owns this run is still alive. The
     * command line is checked too, because Linux reuses process ids.
     */
    public function hasLiveProcess(): bool
    {
        if ($this->pid === null || ! is_readable("/proc/{$this->pid}/cmdline")) {
            return false;
        }

        $commandLine = (string) @file_get_contents("/proc/{$this->pid}/cmdline");

        return str_contains($commandLine, 'artisan') && str_contains($commandLine, 'pipeline:');
    }

    /**
     * A run is busy while it waits to start or while its process is alive.
     */
    public function isBusy(): bool
    {
        return $this->status === RunStatus::Pending
            || ($this->status === RunStatus::Running && $this->hasLiveProcess());
    }

    /**
     * Directory on disk for this run's large payloads, check output and plan copies.
     */
    public function storagePath(string $path = ''): string
    {
        $base = rtrim(config('pipeline.storage_path'), '/').'/'.$this->id;

        return $path === '' ? $base : $base.'/'.ltrim($path, '/');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'pushed' => 'boolean',
            'fix_attempts' => 'integer',
            'cost_usd' => 'decimal:6',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
