<?php

namespace App\Http\Resources;

use App\Models\RunEvent;
use App\Pipeline\Reporters\EventFormatter;
use App\Support\Duration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Expects the run relation to be set, for the offset from the run's start.
 *
 * @mixin RunEvent
 */
class RunEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $formatter = app(EventFormatter::class);

        return [
            'sequence' => $this->sequence,
            'phase' => $this->phase->value,
            'type' => $this->type,
            'tool' => $this->tool,
            'summary' => $this->summary,
            'kind' => $formatter->kind($this->resource),
            'glyph' => $formatter->glyph($this->resource),
            'offset' => Duration::offset($this->run->started_at, $this->occurred_at),
            'time' => $this->occurred_at->format('H:i:s'),
            'payload_on_disk' => $this->payload_path !== null,
        ];
    }
}
