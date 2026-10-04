<?php

namespace App\Models;

use App\Enums\Phase;
use Carbon\CarbonImmutable;
use Database\Factories\RunEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $run_id
 * @property int|null $run_step_id
 * @property Phase $phase
 * @property int $sequence
 * @property string $type
 * @property string|null $tool
 * @property string|null $summary
 * @property array<string, mixed>|null $payload
 * @property string|null $payload_path
 * @property CarbonImmutable $occurred_at
 */
#[Fillable([
    'run_id', 'run_step_id', 'phase', 'sequence', 'type', 'tool', 'summary', 'payload', 'payload_path', 'occurred_at',
])]
class RunEvent extends Model
{
    /** @use HasFactory<RunEventFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /**
     * @return BelongsTo<RunStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(RunStep::class, 'run_step_id');
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
            'payload' => 'array',
            'occurred_at' => 'datetime:Y-m-d H:i:s.v',
        ];
    }
}
