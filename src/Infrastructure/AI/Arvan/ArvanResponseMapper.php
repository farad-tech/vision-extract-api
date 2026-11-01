<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Arvan;

use App\Domain\AI\Exception\AIProviderException;
use App\Domain\AI\ValueObject\AIResponse;
use App\Domain\AI\ValueObject\Usage;

final class ArvanResponseMapper
{
    public function map(string $json, string $requestedModel, float $duration): AIResponse
    {
        try {
            $body = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AIProviderException('invalid_response', 'The AI provider returned an invalid response.');
        }
        $choice = $body['choices'][0] ?? null;
        $text = $choice['message']['content'] ?? null;
        if (!is_string($text) || trim($text) === '' || !empty($choice['message']['refusal']) || ($choice['finish_reason'] ?? 'stop') !== 'stop') {
            throw new AIProviderException('invalid_response', 'The AI provider returned no complete extraction.');
        }
        $usage = $body['usage'] ?? [];
        if (!is_array($usage)) {
            throw new AIProviderException('invalid_response', 'The AI provider returned invalid usage.');
        }
        $count = static function (mixed $value): ?int {
            if ($value === null) {
                return null;
            }
            if (!is_int($value) || $value < 0) {
                throw new AIProviderException('invalid_response', 'The AI provider returned invalid usage.');
            }
            return $value;
        };
        $model = $body['model'] ?? $requestedModel;
        if (!is_string($model) || $model === '') {
            throw new AIProviderException('invalid_response', 'The AI provider returned an invalid model.');
        }

        return new AIResponse($text, $model, new Usage(
            inputTokens: $count($usage['prompt_tokens'] ?? null),
            outputTokens: $count($usage['completion_tokens'] ?? null),
            totalTokens: $count($usage['total_tokens'] ?? null),
            imageTokens: $count($usage['prompt_tokens_details']['image_tokens'] ?? null),
            reasoningTokens: $count($usage['completion_tokens_details']['reasoning_tokens'] ?? null),
        ), $duration);
    }
}
