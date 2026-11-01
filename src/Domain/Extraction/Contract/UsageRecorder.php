<?php

declare(strict_types=1);

namespace App\Domain\Extraction\Contract;

use App\Domain\AI\ValueObject\AIResponse;

interface UsageRecorder
{
    public function start(string $requestId, string $model, ?string $idempotencyKey): void;
    public function finish(string $requestId, string $status, ?AIResponse $response, ?array $cost): void;
}
