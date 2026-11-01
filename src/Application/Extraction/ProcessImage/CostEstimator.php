<?php

declare(strict_types=1);

namespace App\Application\Extraction\ProcessImage;

use App\Domain\AI\ValueObject\AIResponse;

final readonly class CostEstimator
{
    public function __construct(private array $pricing)
    {
    }

    public function estimate(AIResponse $response): ?array
    {
        $rates = $this->pricing[$response->model] ?? null;
        if (!is_array($rates) || $response->usage->inputTokens === null || $response->usage->outputTokens === null) {
            return null;
        }
        foreach (['input_per_million', 'output_per_million'] as $field) {
            if (!isset($rates[$field]) || !is_numeric($rates[$field]) || !is_finite((float) $rates[$field]) || (float) $rates[$field] < 0) {
                return null;
            }
        }
        if (!isset($rates['currency']) || !is_string($rates['currency']) || $rates['currency'] === '') {
            return null;
        }
        // Image/reasoning breakdowns are not added: they may already be included.
        $amount = ($response->usage->inputTokens * (float) $rates['input_per_million'] + $response->usage->outputTokens * (float) $rates['output_per_million']) / 1e6;
        return ['amount' => number_format($amount, 8, '.', ''), 'currency' => $rates['currency']];
    }
}
