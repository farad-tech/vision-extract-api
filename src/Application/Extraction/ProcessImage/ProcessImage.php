<?php

declare(strict_types=1);

namespace App\Application\Extraction\ProcessImage;

use App\Application\Extraction\ValidateExtraction\StructuredOutputValidator;
use App\Domain\AI\Contract\AIProvider;
use App\Domain\AI\ValueObject\{AIRequest, ImageInput};
use App\Domain\Extraction\Contract\UsageRecorder;

final readonly class ProcessImage
{
    public function __construct(
        private AIProvider $provider,
        private ModelRegistry $models,
        private StructuredOutputValidator $validator,
        private UsageRecorder $usageRecorder,
        private CostEstimator $costEstimator,
    ) {
    }

    public function process(ImageInput $image, string $instructions, string $schemaJson, ?string $model, string $requestId, ?string $idempotencyKey = null): array
    {
        $schema = $this->validator->parseSchema($schemaJson);
        $selected = $this->models->resolve($model);
        $this->usageRecorder->start($requestId, $selected->id, $idempotencyKey);
        $response = null;
        $cost = null;
        try {
            $response = $this->provider->generate(new AIRequest($selected, $image, $instructions, $schemaJson));
            $cost = $this->costEstimator->estimate($response);
            $data = $this->validator->validate($response->text, $schema);
        } catch (\Throwable $error) {
            $this->usageRecorder->finish($requestId, $response === null ? 'provider_error' : 'invalid_output', $response, $cost);
            throw $error;
        }
        $this->usageRecorder->finish($requestId, 'success', $response, $cost);
        return [
            'success' => true,
            'request_id' => $requestId,
            'model' => $response->model,
            'data' => $data,
            'usage' => array_filter([
                'input_tokens' => $response->usage->inputTokens,
                'output_tokens' => $response->usage->outputTokens,
                'total_tokens' => $response->usage->totalTokens,
                'image_tokens' => $response->usage->imageTokens,
                'reasoning_tokens' => $response->usage->reasoningTokens,
            ], static fn ($value) => $value !== null),
            'duration_seconds' => $response->durationSeconds,
            'estimated_cost' => $cost,
        ];
    }
}
