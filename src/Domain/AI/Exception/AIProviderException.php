<?php

declare(strict_types=1);

namespace App\Domain\AI\Exception;

/** Safe public failure: never retain the original exception or upstream body. */
final class AIProviderException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
