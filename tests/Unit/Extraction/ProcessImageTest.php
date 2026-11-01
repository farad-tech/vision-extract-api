<?php

declare(strict_types=1);

namespace App\Tests\Unit\Extraction;

use App\Application\Extraction\Exception\ExtractionException;
use App\Application\Extraction\ProcessImage\{CostEstimator, ModelRegistry, ProcessImage};
use App\Application\Extraction\ValidateExtraction\StructuredOutputValidator;
use App\Domain\AI\Contract\AIProvider;
use App\Domain\AI\ValueObject\{AIResponse, ImageInput, Usage};
use App\Infrastructure\Persistence\SqliteUsageRecorder;
use PHPUnit\Framework\TestCase;

final class ProcessImageTest extends TestCase
{
    public function testInvalidOutputStillRecordsActualUsageAndPreventsDuplicateCall(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'usage-test-');
        try {
            $provider = $this->createMock(AIProvider::class);
            $provider->expects(self::once())->method('generate')->willReturn(new AIResponse('{"price":"wrong"}', 'vision', new Usage(20, 10), 0.5));
            $processor = new ProcessImage($provider, new ModelRegistry(['vision' => ['vision' => true]], 'vision'), new StructuredOutputValidator(), new SqliteUsageRecorder($path), new CostEstimator([]));
            $image = new ImageInput('test', 'image/png');
            $schema = '{"type":"object","properties":{"price":{"type":"number"}}}';
            try {
                $processor->process($image, 'Read.', $schema, null, 'request-1', 'same-key');
                self::fail('Expected invalid output.');
            } catch (ExtractionException $error) {
                self::assertSame('invalid_output', $error->errorCode);
            }
            $row = (new \PDO('sqlite:'.$path))->query('SELECT * FROM usage_records')->fetch(\PDO::FETCH_ASSOC);
            self::assertSame('invalid_output', $row['status']);
            self::assertSame(20, $row['input_tokens']);
            self::assertNull($row['total_tokens']);
            self::assertArrayNotHasKey('image', $row);
            try {
                $processor->process($image, 'Read.', $schema, null, 'request-2', 'same-key');
                self::fail('Expected duplicate.');
            } catch (ExtractionException $error) {
                self::assertSame(409, $error->status);
            }
        } finally {
            unlink($path);
        }
    }

    public function testUnknownModelNeverCallsProvider(): void
    {
        $registry = new ModelRegistry(['text-only' => ['vision' => false]], 'text-only');
        $this->expectException(ExtractionException::class);
        $registry->resolve(null);
    }

    public function testCostRequiresConfiguredPricesAndReportedCounts(): void
    {
        $cost = new CostEstimator(['model' => ['input_per_million' => '1', 'output_per_million' => '2', 'currency' => 'USD']]);
        self::assertNull($cost->estimate(new AIResponse('{}', 'model', new Usage(), 0.1)));
        self::assertSame(['amount' => '0.00014000', 'currency' => 'USD'], $cost->estimate(new AIResponse('{}', 'model', new Usage(100, 20, reasoningTokens: 5), 0.1)));
    }
}
