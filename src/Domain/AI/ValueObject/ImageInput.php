<?php

declare(strict_types=1);

namespace App\Domain\AI\ValueObject;

/** Validated in-memory bytes; adapters own wire encoding. Never log or persist bytes. */
final readonly class ImageInput
{
    public function __construct(
        #[\SensitiveParameter]
        public string $bytes,
        public string $mimeType,
    ) {
    }
}
