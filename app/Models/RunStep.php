<?php

namespace App\Models;

use App\Enums\Phase;
use App\Enums\StepStatus;
use Carbon\CarbonImmutable;
use Database\Factories\RunStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $run_id
 * @property Phase $phase
 * @property int $attempt
 * @property StepStatus $status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $duration_ms
 * @property int|null $exit_code
 * @property string|null $session_id
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_creation_tokens
 * @property int $cache_read_tokens
 * @property string $cost_usd
 * @property string|null $output_path
 * @property string|null $fingerprint
 * @property string|null $error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'run_id', 'phase', 'attempt', 'status', 'started_at', 'finished_at', 'duration_ms', 'exit_code',
    'session_id', 'input_tokens', 'output_tokens', 'cache_creation_tokens', 'cache_read_tokens', 'cost_usd',
    'output_path', 'fingerprint', 'error',
])]
class RunStep extends Model
{
    /** @use HasFactory<RunStepFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phase' => Phase::class,
            'status' => StepStatus::class,
            'cost_usd' => 'decimal:6',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
