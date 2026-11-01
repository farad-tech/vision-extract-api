<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Application\Extraction\Exception\ExtractionException;
use App\Application\Extraction\ProcessImage\ProcessImage;
use App\Infrastructure\Image\ImageValidator;
use App\Presentation\Http\Request\ExtractionRequestMapper;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ExtractionController
{
    public function __construct(private ExtractionRequestMapper $mapper, private ImageValidator $images, private ProcessImage $processor)
    {
    }

    #[Route('/api/v1/extractions', name: 'api_extractions', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $dto = $this->mapper->map($request);
        $image = $request->files->get('image');
        if (!$image instanceof UploadedFile) {
            throw new ExtractionException('invalid_image', 'Exactly one uploaded image is required.');
        }
        $key = $request->headers->get('Idempotency-Key');
        if ($key !== null && !preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $key)) {
            throw new ExtractionException('invalid_request', 'Invalid Idempotency-Key.');
        }
        $result = $this->processor->process($this->images->validate($image), $dto->instructions, $dto->schema, $dto->model, $request->attributes->get('request_id'), $key);
        // An empty usage object must remain a JSON object, not an array.
        $result['usage'] = (object) $result['usage'];
        $response = new JsonResponse();
        $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        return $response->setData($result);
    }
}
