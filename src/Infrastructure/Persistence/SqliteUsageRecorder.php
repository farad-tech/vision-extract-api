<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Extraction\Exception\ExtractionException;
use App\Domain\AI\ValueObject\AIResponse;
use App\Domain\Extraction\Contract\UsageRecorder;

final class SqliteUsageRecorder implements UsageRecorder
{
    private ?\PDO $connection = null;

    public function __construct(private readonly string $path)
    {
    }

    private function database(): \PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }
        try {
            if (!is_dir(dirname($this->path)) && !mkdir(dirname($this->path), 0700, true) && !is_dir(dirname($this->path))) {
                throw new \RuntimeException();
            }
            $db = new \PDO('sqlite:'.$this->path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $db->exec('PRAGMA busy_timeout=5000');
            $db->exec('CREATE TABLE IF NOT EXISTS usage_records (
                request_id TEXT PRIMARY KEY, idempotency_hash TEXT UNIQUE, model TEXT NOT NULL,
                status TEXT NOT NULL, created_at TEXT NOT NULL, duration_seconds REAL,
                input_tokens INTEGER, output_tokens INTEGER, total_tokens INTEGER,
                image_tokens INTEGER, reasoning_tokens INTEGER, estimated_cost TEXT, currency TEXT
            )');
            return $this->connection = $db;
        } catch (\Throwable) {
            throw new ExtractionException('storage_unavailable', 'Usage storage is unavailable.', 503);
        }
    }

    public function start(string $requestId, string $model, ?string $idempotencyKey): void
    {
        try {
            $statement = $this->database()->prepare('INSERT INTO usage_records (request_id, idempotency_hash, model, status, created_at) VALUES (?, ?, ?, ?, ?)');
            $statement->execute([$requestId, $idempotencyKey === null ? null : hash('sha256', $idempotencyKey), $model, 'processing', gmdate('c')]);
        } catch (\PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 19) {
                throw new ExtractionException('duplicate_request', 'This idempotency key has already been used. Inspect the original request before retrying.', 409);
            }
            throw new ExtractionException('storage_unavailable', 'Usage storage is unavailable.', 503);
        }
    }

    public function finish(string $requestId, string $status, ?AIResponse $response, ?array $cost): void
    {
        try {
            $statement = $this->database()->prepare('UPDATE usage_records SET status=?, model=COALESCE(?,model), duration_seconds=?, input_tokens=?, output_tokens=?, total_tokens=?, image_tokens=?, reasoning_tokens=?, estimated_cost=?, currency=? WHERE request_id=?');
            $statement->execute([$status, $response?->model, $response?->durationSeconds,
                $response?->usage->inputTokens, $response?->usage->outputTokens, $response?->usage->totalTokens,
                $response?->usage->imageTokens, $response?->usage->reasoningTokens, $cost['amount'] ?? null, $cost['currency'] ?? null, $requestId]);
        } catch (\PDOException) {
            throw new ExtractionException('storage_unavailable', 'Usage storage is unavailable.', 503);
        }
    }
}
