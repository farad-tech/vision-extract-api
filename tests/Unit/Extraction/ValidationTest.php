<?php

declare(strict_types=1);

namespace App\Tests\Unit\Extraction;

use App\Application\Extraction\Exception\ExtractionException;
use App\Application\Extraction\ValidateExtraction\StructuredOutputValidator;
use App\Infrastructure\Image\ImageValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validation;

final class ValidationTest extends TestCase
{
    public function testSchemaPreservesObjectsAndNumericTypes(): void
    {
        $validator = new StructuredOutputValidator();
        $schema = $validator->parseSchema('{"type":"object","properties":{"price":{"type":"number"},"code":{"type":"string"}},"required":["price","code"]}');
        $data = $validator->validate("```json\n{\"price\":12.5,\"code\":\"001\"}\n```", $schema);
        self::assertSame(12.5, $data->price);
        self::assertSame('001', $data->code);
        self::assertInstanceOf(\stdClass::class, $validator->validate('{}', (object) ['type' => 'object']));
    }

    public function testSchemaRejectsInvalidOutput(): void
    {
        $this->expectException(ExtractionException::class);
        (new StructuredOutputValidator())->validate('{"price":"12"}', json_decode('{"type":"object","properties":{"price":{"type":"number"}}}'));
    }

    public function testExternalReferencesAreRejectedBeforeInference(): void
    {
        $this->expectException(ExtractionException::class);
        (new StructuredOutputValidator())->parseSchema('{"$ref":"file:///etc/passwd"}');
    }

    public function testMalformedSchemaIsRejected(): void
    {
        $this->expectException(ExtractionException::class);
        (new StructuredOutputValidator())->parseSchema('{"type":"imaginary"}');
    }

    public function testImageUsesActualMimeAndIgnoresFilename(): void
    {
        $path = $this->image();
        try {
            $image = (new ImageValidator(Validation::createValidator()))->validate(new UploadedFile($path, 'evil.php', 'text/plain', test: true));
            self::assertSame('image/png', $image->mimeType);
        } finally {
            unlink($path);
        }
    }

    public function testDimensionsAreRejectedBeforeDecode(): void
    {
        $path = $this->image();
        try {
            $this->expectException(ExtractionException::class);
            (new ImageValidator(Validation::createValidator(), maxWidth: 1))->validate(new UploadedFile($path, 'image.png', test: true));
        } finally {
            unlink($path);
        }
    }

    public function testFakeMimeIsRejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'image-test-');
        file_put_contents($path, '<?php echo 1;');
        try {
            $this->expectException(ExtractionException::class);
            (new ImageValidator(Validation::createValidator()))->validate(new UploadedFile($path, 'image.png', 'image/png', test: true));
        } finally {
            unlink($path);
        }
    }

    public function testOversizedFileIsRejected(): void
    {
        $path = $this->image();
        try {
            $this->expectException(ExtractionException::class);
            (new ImageValidator(Validation::createValidator(), maxBytes: 10))->validate(new UploadedFile($path, 'x.png', test: true));
        } finally {
            unlink($path);
        }
    }

    public function testCorruptedPngIsRejected(): void
    {
        $path = $this->image();
        file_put_contents($path, substr(file_get_contents($path), 0, -12));
        try {
            $this->expectException(ExtractionException::class);
            (new ImageValidator(Validation::createValidator()))->validate(new UploadedFile($path, 'x.png', test: true));
        } finally {
            unlink($path);
        }
    }

    public function testMalformedJsonIsRejected(): void
    {
        $this->expectException(ExtractionException::class);
        (new StructuredOutputValidator())->validate('{broken', true);
    }

    public function testArrayDoesNotSatisfyObjectSchema(): void
    {
        $this->expectException(ExtractionException::class);
        (new StructuredOutputValidator())->validate('[]', (object) ['type' => 'object']);
    }

    public function testLocalReferenceAndReservedPropertyNameAreSupported(): void
    {
        $validator = new StructuredOutputValidator();
        $schema = $validator->parseSchema('{"$defs":{"value":{"type":"string"}},"type":"object","properties":{"$ref":{"$ref":"#/$defs/value"}}}');
        self::assertSame('hello', $validator->validate('{"$ref":"hello"}', $schema)->{'$ref'});
    }

    private function image(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'image-test-');
        $image = imagecreatetruecolor(2, 2);
        imagepng($image, $path);
        imagedestroy($image);
        return $path;
    }
}
