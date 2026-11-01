<?php

declare(strict_types=1);

namespace App\Domain\AI\ValueObject;

/** Generated text is untrusted and is not yet a valid extraction result. */
final readonly class AIResponse
{
    public function __construct(
        public string $text,
        // Provider-reported model, or the requested ID when none is reported.
        public string $model,
        public Usage $usage,
        // Measured adapter round-trip time, including any controlled retries.
        public float $durationSeconds,
    ) {
    }
}
