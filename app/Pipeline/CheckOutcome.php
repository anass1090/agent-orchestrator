<?php

namespace App\Pipeline;

use App\Enums\Phase;

class CheckOutcome
{
    public function __construct(
        public readonly bool $passed,
        public readonly ?Phase $failedCheck = null,
        public readonly string $output = '',
        public readonly ?string $fingerprint = null,
        /** @var list<string> */
        public readonly array $command = [],
    ) {}

    public static function passed(): self
    {
        return new self(true);
    }
}
