<?php

declare(strict_types=1);

namespace App\Domain\AI\ValueObject;

/** Resolved from trusted application configuration, never from client capabilities. */
final readonly class Model
{
    public function __construct(
        public string $id,
        public bool $supportsVision,
        public bool $supportsStructuredOutput,
    ) {
    }
}
