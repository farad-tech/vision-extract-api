<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI;

use App\Domain\AI\Exception\AIProviderException;
use App\Domain\AI\ValueObject\{AIRequest, ImageInput, Model};
use App\Infrastructure\AI\Arvan\{ArvanAIProvider, ArvanRequestMapper, ArvanResponseMapper};
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ArvanAIProviderTest extends TestCase
{
    private function request(bool $structured = false): AIRequest
    {
        return new AIRequest(new Model('test-model', true, $structured), new ImageInput('image-bytes', 'image/png'), 'Extract items.', '{"type":"object"}');
    }

    public function testCandidateMappingWithExplicitVerifiedConfiguration(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://example.test/model/v1/chat/completions', $url);
            self::assertContains('Authorization: apikey test-key', $options['headers']);
            $body = json_decode($options['body'], true);
            self::assertSame('data:image/png;base64,'.base64_encode('image-bytes'), $body['messages'][1]['content'][1]['image_url']['url']);
            self::assertArrayNotHasKey('response_format', $body);
            return new MockResponse('{"choices":[{"message":{"content":"{}"},"finish_reason":"stop"}],"usage":{"prompt_tokens":12,"completion_tokens":3}}');
        });
        $result = $this->provider($client)->generate($this->request());
        self::assertSame('{}', $result->text);
        self::assertSame(12, $result->usage->inputTokens);
        self::assertNull($result->usage->totalTokens);
        self::assertNull($result->usage->imageTokens);
    }

    public function testNativeSchemaIsConditional(): void
    {
        $mapped = (new ArvanRequestMapper())->map($this->request(true));
        self::assertSame('json_schema', $mapped['response_format']['type']);
        self::assertInstanceOf(\stdClass::class, $mapped['response_format']['json_schema']['schema']);
    }

    public function testUnverifiedEndpointMakesNoRequest(): void
    {
        $client = new MockHttpClient(static fn () => throw new \LogicException('Unexpected network call'));
        $provider = new ArvanAIProvider($client, new ArvanRequestMapper(), new ArvanResponseMapper(), [], 'test');
        $this->expectException(AIProviderException::class);
        $provider->generate($this->request());
    }

    public function testUnauthorizedIsNotRetriedAndDoesNotLeakBody(): void
    {
        $client = new MockHttpClient(new MockResponse('secret upstream body', ['http_code' => 401]));
        try {
            $this->provider($client, 2)->generate($this->request());
            self::fail('Expected provider error.');
        } catch (AIProviderException $error) {
            self::assertSame('upstream_error', $error->reason);
            self::assertStringNotContainsString('secret', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertSame(1, $client->getRequestsCount());
        }
    }

    public function testRateLimitCanBeRetriedWithinConfiguredLimit(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 0']]),
            new MockResponse('{"choices":[{"message":{"content":"{}"}}]}'),
        ]);
        self::assertSame('{}', $this->provider($client, 1)->generate($this->request())->text);
        self::assertSame(2, $client->getRequestsCount());
    }

    public function testTruncatedOutputIsRejected(): void
    {
        $this->expectException(AIProviderException::class);
        (new ArvanResponseMapper())->map('{"choices":[{"message":{"content":"{}"},"finish_reason":"length"}]}', 'model', 1.0);
    }

    public function testMalformedEnvelopeIsRejected(): void
    {
        $this->expectException(AIProviderException::class);
        (new ArvanResponseMapper())->map('not-json', 'model', 0.1);
    }

    public function testMissingUsageRemainsUnknown(): void
    {
        $result = (new ArvanResponseMapper())->map('{"choices":[{"message":{"content":"{}"}}]}', 'model', 0.1);
        self::assertNull($result->usage->inputTokens);
        self::assertNull($result->usage->totalTokens);
    }

    public function testUsageBreakdownsAreMappedWithoutInferringTotals(): void
    {
        $result = (new ArvanResponseMapper())->map('{"choices":[{"message":{"content":"{}"}}],"usage":{"prompt_tokens_details":{"image_tokens":2},"completion_tokens_details":{"reasoning_tokens":3}}}', 'model', 0.1);
        self::assertSame(2, $result->usage->imageTokens);
        self::assertSame(3, $result->usage->reasoningTokens);
        self::assertNull($result->usage->totalTokens);
    }

    public function testTransportFailureIsNotRetried(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('private endpoint and key');
        });
        try {
            $this->provider($client, 2)->generate($this->request());
            self::fail('Expected failure.');
        } catch (AIProviderException $error) {
            self::assertSame('upstream_error', $error->reason);
            self::assertStringNotContainsString('private', $error->getMessage());
        }
    }

    public function testTimeoutIsNormalized(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new \Symfony\Component\HttpClient\Exception\TimeoutException('private URL');
        });
        try {
            $this->provider($client)->generate($this->request());
            self::fail('Expected timeout.');
        } catch (AIProviderException $error) {
            self::assertSame('timeout', $error->reason);
        }
    }

    public function testResponseSizeIsBounded(): void
    {
        $client = new MockHttpClient(new MockResponse(str_repeat('x', 1048577)));
        $this->expectException(AIProviderException::class);
        $this->provider($client)->generate($this->request());
    }

    public function testSharedBaseUriIsUsedWhenModelHasNoOverride(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            self::assertSame('https://api.arvancloudai.ir/v1/chat/completions', $url);
            return new MockResponse('{"choices":[{"message":{"content":"{}"}}]}');
        });
        $provider = new ArvanAIProvider($client, new ArvanRequestMapper(), new ArvanResponseMapper(), [
            'test-model' => ['image_profile_verified' => true],
        ], 'test-key', baseUri: 'https://api.arvancloudai.ir/v1/');
        self::assertSame('{}', $provider->generate($this->request())->text);
    }

    private function provider(MockHttpClient $client, int $retries = 0): ArvanAIProvider
    {
        return new ArvanAIProvider($client, new ArvanRequestMapper(), new ArvanResponseMapper(), [
            'test-model' => ['base_uri' => 'https://example.test/model/v1', 'image_profile_verified' => true],
        ], 'test-key', maxRetries: $retries);
    }
}
