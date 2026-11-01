<?php

declare(strict_types=1);

namespace App\Infrastructure\Image;

use App\Application\Extraction\Exception\ExtractionException;
use App\Domain\AI\ValueObject\ImageInput;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class ImageValidator
{
    public function __construct(
        private ValidatorInterface $validator,
        private int $maxBytes = 10485760,
        private int $maxWidth = 8192,
        private int $maxHeight = 8192,
        private int $maxPixels = 40000000,
    ) {
        if (min($maxBytes, $maxWidth, $maxHeight, $maxPixels) < 1) {
            throw new \InvalidArgumentException('Image limits must be positive.');
        }
    }

    public function validate(UploadedFile $file): ImageInput
    {
        if (!$file->isValid()) {
            throw new ExtractionException('invalid_image', 'Image upload failed.');
        }
        // Inspect size, actual MIME and dimensions BEFORE attempting decompression.
        $constraints = new Assert\Image(
            maxSize: $this->maxBytes,
            mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
            maxWidth: $this->maxWidth,
            maxHeight: $this->maxHeight,
            maxPixels: $this->maxPixels,
            detectCorrupted: false,
        );
        if (count($this->validator->validate($file, $constraints)) > 0) {
            throw new ExtractionException('invalid_image', 'Image format, size or dimensions are invalid.');
        }
        $dimensions = getimagesize($file->getPathname());
        $memoryLimit = ini_parse_quantity(ini_get('memory_limit'));
        $estimatedMemory = $dimensions[0] * $dimensions[1] * 16 + $file->getSize() * 3 + 16777216;
        if ($memoryLimit > 0 && $estimatedMemory > $memoryLimit - memory_get_usage(true)) {
            throw new ExtractionException('invalid_image', 'Image decoding would exceed the available memory budget.');
        }
        $bytes = file_get_contents($file->getPathname());
        if ($bytes === false || strlen($bytes) > $this->maxBytes) {
            throw new ExtractionException('invalid_image', 'Image cannot be read within the size limit.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $signatureValid = match ($mime) {
            'image/jpeg' => str_starts_with($bytes, "\xff\xd8\xff") && str_ends_with($bytes, "\xff\xd9"),
            'image/png' => str_starts_with($bytes, "\x89PNG\r\n\x1a\n") && str_ends_with($bytes, "\x00\x00\x00\x00IEND\xaeB\x60\x82"),
            'image/webp' => str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' && unpack('V', substr($bytes, 4, 4))[1] + 8 === strlen($bytes),
            default => false,
        };
        if (!$signatureValid || count($this->validator->validate($file, new Assert\Image(detectCorrupted: true))) > 0) {
            throw new ExtractionException('invalid_image', 'Image is corrupt or has an invalid signature.');
        }

        return new ImageInput($bytes, $mime);
    }
}
