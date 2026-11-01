<?php

declare(strict_types=1);

namespace App\Presentation\Http\EventSubscriber;

use App\Application\Extraction\Exception\ExtractionException;
use App\Domain\AI\Exception\AIProviderException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\{ExceptionEvent, RequestEvent, ResponseEvent};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ApiSubscriber implements EventSubscriberInterface
{
    public function __construct(#[\SensitiveParameter] private string $apiKey, private int $maxRequestBytes = 11534336)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 256], KernelEvents::EXCEPTION => ['onException', 128], KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }
        $hex = bin2hex(random_bytes(16));
        $id = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3).'-'.dechex((hexdec($hex[16]) & 3) | 8).substr($hex, 17, 3).'-'.substr($hex, 20);
        $request->attributes->set('request_id', $id);
        if ($this->apiKey === '') {
            throw new ExtractionException('not_configured', 'API authentication is not configured.', 503);
        }
        $header = $request->headers->get('Authorization', '');
        if (!preg_match('/\ABearer (\S+)\z/i', $header, $match) || !hash_equals($this->apiKey, $match[1])) {
            throw new ExtractionException('unauthorized', 'A valid Bearer API key is required.', 401);
        }
        $length = $request->headers->get('Content-Length');
        if ($length !== null && (!ctype_digit($length) || (float) $length > $this->maxRequestBytes)) {
            throw new ExtractionException('request_too_large', 'Request exceeds the size limit.', 413);
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }
        $error = $event->getThrowable();
        $status = 500;
        $code = 'internal_error';
        $message = 'The request could not be completed.';
        if ($error instanceof ExtractionException) {
            [$status, $code, $message] = [$error->status, $error->errorCode, $error->getMessage()];
        } elseif ($error instanceof AIProviderException) {
            $status = match ($error->reason) { 'timeout' => 504, 'not_configured', 'rate_limited' => 503, default => 502 };
            [$code, $message] = ['provider_'.$error->reason, $error->getMessage()];
        } elseif ($error instanceof HttpExceptionInterface) {
            $status = $error->getStatusCode();
            $code = 'http_error';
            $message = 'The requested API resource or method is unavailable.';
        }
        $headers = $status === 401 ? ['WWW-Authenticate' => 'Bearer'] : [];
        $event->setResponse(new JsonResponse(['success' => false, 'request_id' => $request->attributes->get('request_id'), 'error' => ['code' => $code, 'message' => $message]], $status, $headers));
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($id = $event->getRequest()->attributes->get('request_id')) {
            $event->getResponse()->headers->set('X-Request-ID', $id);
            $event->getResponse()->headers->set('Cache-Control', 'no-store');
        }
    }
}
