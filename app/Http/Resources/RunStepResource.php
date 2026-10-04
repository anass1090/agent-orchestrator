<?php

namespace App\Http\Resources;

use App\Models\RunStep;
use App\Support\Duration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RunStep
 */
class RunStepResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $durationMs = $this->duration_ms ?? (int) $this->started_at->diffInMilliseconds(now());

        return [
            'id' => $this->id,
            'phase' => $this->phase->value,
            'attempt' => $this->attempt,
            'status' => $this->status->value,
            'error' => $this->error,
            'exit_code' => $this->exit_code,
            'duration_ms' => $durationMs,
            'duration' => Duration::format($durationMs),
            'cost_usd' => $this->phase->isAgent() ? (float) $this->cost_usd : null,
        ];
    }
}
