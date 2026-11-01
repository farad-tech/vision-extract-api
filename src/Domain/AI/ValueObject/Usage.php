<?php

declare(strict_types=1);

namespace App\Domain\AI\ValueObject;

/** Null means unreported. Never infer totals or replace missing counts with zero. */
final readonly class Usage
{
    public function __construct(
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $totalTokens = null,
        public ?int $imageTokens = null,
        public ?int $reasoningTokens = null,
    ) {
    }
}
