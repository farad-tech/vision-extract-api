<?php

declare(strict_types=1);

namespace App\Infrastructure\AI\Arvan;

use App\Domain\AI\Contract\AIProvider;
use App\Domain\AI\Exception\AIProviderException;
use App\Domain\AI\ValueObject\AIRequest;
use App\Domain\AI\ValueObject\AIResponse;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ArvanAIProvider implements AIProvider
{
    public function __construct(
        private HttpClientInterface $client,
        private ArvanRequestMapper $requestMapper,
        private ArvanResponseMapper $responseMapper,
        private array $endpoints,
        #[\SensitiveParameter] private string $apiKey,
        private float $timeoutSeconds = 60,
        private int $maxRetries = 0,
        private string $baseUri = '',
    ) {
        if ($timeoutSeconds <= 0 || $maxRetries < 0 || $maxRetries > 3) {
            throw new \InvalidArgumentException('Invalid AI timeout or retry limit.');
        }
    }

    public function generate(#[\SensitiveParameter] AIRequest $request): AIResponse
    {
        $config = $this->endpoints[$request->model->id] ?? [];
        if (($config['image_profile_verified'] ?? false) !== true || ($request->model->supportsStructuredOutput && ($config['structured_output_verified'] ?? false) !== true)) {
            throw new AIProviderException('not_configured', 'Image or structured-output compatibility has not been verified for this endpoint.');
        }
        $base = $config['base_uri'] ?? $this->baseUri;
        $url = is_string($base) ? parse_url($base) : false;
        if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || trim($this->apiKey) === '' || preg_match('/[\r\n]/', $this->apiKey)) {
            throw new AIProviderException('not_configured', 'The AI endpoint or credential is not configured.');
        }
        $payload = $this->requestMapper->map($request);
        $start = hrtime(true);
        for ($attempt = 0; ; ++$attempt) {
            $remaining = $this->timeoutSeconds - (hrtime(true) - $start) / 1e9;
            if ($remaining <= 0) {
                throw new AIProviderException('timeout', 'The AI provider timed out.');
            }
            try {
                $response = $this->client->request('POST', rtrim($base, '/').'/chat/completions', [
                    'headers' => ['Authorization' => 'apikey '.$this->apiKey, 'Accept' => 'application/json'],
                    'json' => $payload,
                    'timeout' => $remaining,
                    'max_duration' => $remaining,
                    'max_redirects' => 0,
                    'buffer' => false,
                ]);
                $status = $response->getStatusCode();
                if (in_array($status, [429, 503], true) && $attempt < $this->maxRetries) {
                    $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;
                    $response->cancel();
                    $delay = 0.25 * (2 ** $attempt);
                    if (is_string($retryAfter)) {
                        if (ctype_digit($retryAfter)) {
                            $delay = (float) $retryAfter;
                        } elseif (($retryAt = strtotime($retryAfter)) !== false) {
                            $delay = max(0, $retryAt - time());
                        }
                    }
                    if ($delay > 2 || $delay >= $remaining) {
                        throw new AIProviderException('rate_limited', 'The AI provider is temporarily unavailable.');
                    }
                    usleep((int) ($delay * 1e6));
                    continue;
                }
                if ($status !== 200) {
                    $response->cancel();
                    throw new AIProviderException($status === 429 ? 'rate_limited' : 'upstream_error', 'The AI provider could not complete the request.');
                }
                $json = '';
                foreach ($this->client->stream($response) as $chunk) {
                    $json .= $chunk->getContent();
                    if (strlen($json) > 1048576) {
                        $response->cancel();
                        throw new AIProviderException('invalid_response', 'The AI provider response exceeds the size limit.');
                    }
                }
                return $this->responseMapper->map($json, $request->model->id, (hrtime(true) - $start) / 1e9);
            } catch (TimeoutExceptionInterface) {
                throw new AIProviderException('timeout', 'The AI provider timed out.');
            } catch (TransportExceptionInterface) {
                // Ambiguous delivery: do not repeat a potentially billable inference.
                throw new AIProviderException('upstream_error', 'The AI provider connection failed.');
            }
        }
    }
}
