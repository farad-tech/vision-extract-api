<?php

declare(strict_types=1);

namespace App\Domain\AI\ValueObject;

/** Input is validated by the application; JSON Schema remains JSON to preserve types. */
final readonly class AIRequest
{
    public function __construct(
        public Model $model,
        public ImageInput $image,
        public string $instructions,
        public string $jsonSchema,
    ) {
    }
}
