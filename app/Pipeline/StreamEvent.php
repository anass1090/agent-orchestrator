<?php

namespace App\Pipeline;

class StreamEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $tool,
        public readonly ?string $summary,
        public readonly array $payload,
    ) {}
}
