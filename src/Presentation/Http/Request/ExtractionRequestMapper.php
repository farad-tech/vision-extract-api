<?php

declare(strict_types=1);

namespace App\Presentation\Http\Request;

use App\Application\Extraction\Exception\ExtractionException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class ExtractionRequestMapper
{
    public function __construct(private ValidatorInterface $validator)
    {
    }

    public function map(Request $request): ExtractionRequest
    {
        if (!str_starts_with(strtolower($request->headers->get('Content-Type', '')), 'multipart/form-data')) {
            throw new ExtractionException('unsupported_media_type', 'Use multipart/form-data.', 415);
        }
        $fields = $request->request->all();
        foreach (['instructions', 'schema'] as $field) {
            if (!isset($fields[$field]) || !is_string($fields[$field])) {
                throw new ExtractionException('invalid_request', 'Instructions and schema must be strings.');
            }
        }
        if (isset($fields['model']) && !is_string($fields['model'])) {
            throw new ExtractionException('invalid_request', 'Model must be a string.');
        }
        $dto = new ExtractionRequest($fields['instructions'], $fields['schema'], $fields['model'] ?? null);
        if (count($this->validator->validate($dto)) > 0) {
            throw new ExtractionException('invalid_request', 'Instructions, schema or model is empty or exceeds the allowed length.');
        }
        return $dto;
    }
}
