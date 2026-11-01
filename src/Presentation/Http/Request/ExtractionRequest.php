<?php

declare(strict_types=1);

namespace App\Presentation\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ExtractionRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 16000)]
        public string $instructions,
        #[Assert\NotBlank]
        #[Assert\Length(max: 65536, countUnit: 'bytes')]
        public string $schema,
        #[Assert\Length(min: 1, max: 200)]
        public ?string $model,
    ) {
    }
}
