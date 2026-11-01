<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AI\Contract\AIProvider;
use App\Domain\AI\ValueObject\{AIResponse, Usage};
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class KernelTest extends WebTestCase
{
    public function testAuthenticationIsMandatory(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/v1/extractions');
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
        self::assertFalse(json_decode($client->getResponse()->getContent(), true)['success']);
    }

    public function testInvalidAuthorizationIsRejected(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/v1/extractions', server: ['HTTP_AUTHORIZATION' => 'Bearer wrong']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testSuccessfulExtractionUsesProviderAndPreservesJsonTypes(): void
    {
        $client = self::createClient();
        $provider = $this->createMock(AIProvider::class);
        $provider->expects(self::once())->method('generate')->willReturn(new AIResponse('{"price":12.5,"code":"001"}', 'test-model', new Usage(12, 4), 0.1));
        self::getContainer()->set(AIProvider::class, $provider);
        $path = tempnam(sys_get_temp_dir(), 'extraction-test-');
        $image = imagecreatetruecolor(2, 2);
        imagepng($image, $path);
        imagedestroy($image);
        try {
            $client->request('POST', '/api/v1/extractions', ['instructions' => 'Read price.', 'schema' => '{"type":"object","required":["price","code"],"properties":{"price":{"type":"number"},"code":{"type":"string"}}}'],
                ['image' => new UploadedFile($path, 'fake.jpg', 'text/plain', test: true)],
                ['HTTP_AUTHORIZATION' => 'Bearer test-service-key', 'CONTENT_TYPE' => 'multipart/form-data']);
            self::assertResponseIsSuccessful();
            $response = json_decode($client->getResponse()->getContent(), true);
            self::assertSame(12.5, $response['data']['price']);
            self::assertSame('001', $response['data']['code']);
            self::assertSame(12, $response['usage']['input_tokens']);
            self::assertArrayNotHasKey('total_tokens', $response['usage']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $response['request_id']);
        } finally {
            unlink($path);
        }
    }

    public function testJsonBodyInsteadOfMultipartIsRejected(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/v1/extractions', server: ['HTTP_AUTHORIZATION' => 'Bearer test-service-key', 'CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertResponseStatusCodeSame(415);
    }

    public function testOversizedRequestIsRejectedBeforeController(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/v1/extractions', server: ['HTTP_AUTHORIZATION' => 'Bearer test-service-key', 'CONTENT_LENGTH' => '999999999']);
        self::assertResponseStatusCodeSame(413);
    }
    public function testMultipartFieldArraysAreRejected(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/v1/extractions', ['instructions' => ['unexpected'], 'schema' => '{}'], server: ['HTTP_AUTHORIZATION' => 'Bearer test-service-key', 'CONTENT_TYPE' => 'multipart/form-data']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_request', json_decode($client->getResponse()->getContent(), true)['error']['code']);
    }

    public function testMissingImageIsRejected(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/v1/extractions', ['instructions' => 'Read.', 'schema' => '{}'], server: ['HTTP_AUTHORIZATION' => 'Bearer test-service-key', 'CONTENT_TYPE' => 'multipart/form-data']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_image', json_decode($client->getResponse()->getContent(), true)['error']['code']);
    }
}
