<?php

namespace App\Http\Resources;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Support\Duration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Run
 */
class RunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $busy = $this->isBusy();

        // Counts up while the run is busy; a stopped run without a duration has none.
        $durationMs = $this->duration_ms ?? ($busy && $this->started_at ? (int) $this->started_at->diffInMilliseconds(now()) : null);

        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'status' => $this->status->value,
            'busy' => $busy,
            'can_stop' => $this->status === RunStatus::Running && $this->hasLiveProcess(),
            'failed_check' => $this->failed_check,
            'stop_reason' => $this->stop_reason,
            'error' => $this->error,
            'branch' => $this->branch,
            'pushed' => $this->pushed,
            'merged' => $this->merged,
            'model' => $this->model,
            'fix_attempts' => $this->fix_attempts,
            'max_fix_attempts' => (int) config('pipeline.max_fix_attempts'),
            'duration' => Duration::format($durationMs),
            'cost_usd' => (float) $this->cost_usd,
            'tokens_in' => $this->input_tokens + $this->cache_read_tokens + $this->cache_creation_tokens,
            'tokens_out' => $this->output_tokens,
            'started_at' => $this->started_at?->format('d-m-Y H:i:s'),
            'created_at' => $this->created_at?->format('d-m H:i'),
            'project' => $this->whenLoaded('project', fn () => ['id' => $this->project->id, 'name' => $this->project->name]),
            'steps' => $this->whenLoaded('steps', fn () => RunStepResource::collection($this->steps)->resolve()),
        ];
    }
}
