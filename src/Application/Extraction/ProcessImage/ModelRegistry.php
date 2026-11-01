<?php

declare(strict_types=1);

namespace App\Application\Extraction\ProcessImage;

use App\Application\Extraction\Exception\ExtractionException;
use App\Domain\AI\ValueObject\Model;

final readonly class ModelRegistry
{
    public function __construct(private array $models, private string $defaultModel)
    {
    }

    public function resolve(?string $id): Model
    {
        $id = $id ?? $this->defaultModel;
        if ($id === '') {
            throw new ExtractionException('not_configured', 'No default AI model is configured.', 503);
        }
        $config = $this->models[$id] ?? null;
        if (!is_array($config)) {
            throw new ExtractionException('unsupported_model', 'The requested model is not allowed.');
        }
        if (($config['vision'] ?? false) !== true) {
            throw new ExtractionException('unsupported_model', 'The requested model does not have verified image support.');
        }

        return new Model($id, true, ($config['structured_output'] ?? false) === true);
    }
}
