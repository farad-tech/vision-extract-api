<?php

declare(strict_types=1);

namespace App\Domain\AI\Contract;

use App\Domain\AI\Exception\AIProviderException;
use App\Domain\AI\ValueObject\AIRequest;
use App\Domain\AI\ValueObject\AIResponse;

/** Provider boundary only: no HTTP, persistence, or extraction validation here. */
interface AIProvider
{
    /**
     * Generate text from a validated image, instructions and JSON Schema.
     * The application resolves an allowed vision model before calling this method.
     * The adapter uses native structured output when supported, otherwise a JSON
     * prompting fallback. The application MUST parse and validate returned text.
     *
     * @throws AIProviderException On transport, authentication, rate limit,
     *                            unsupported capability or invalid provider envelope.
     */
    public function generate(AIRequest $request): AIResponse;
}
