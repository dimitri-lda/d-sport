<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Domain\DomainException;
use App\Presentation\Http\ApiProblem;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /** @return array<string, array{string, int}> */
    public static function getSubscribedEvents(): array
    {
        // Keep Symfony's exception logging (priority 0) before the JSON response,
        // and replace the standard HTML error renderer (priority -128).
        return [KernelEvents::EXCEPTION => ['onException', -64]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if ('/api' !== $path && !str_starts_with($path, '/api/')) {
            return;
        }

        $exception = $event->getThrowable();
        $fields = [];

        if ($exception instanceof ApiProblem) {
            $status = $exception->status;
            $code = $exception->errorCode;
            $message = $exception->getMessage();
            $fields = $exception->fields;
            $headers = [];
        } else {
            if ($exception instanceof DomainException) {
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            } else {
                $status = $exception instanceof HttpExceptionInterface
                    ? $exception->getStatusCode()
                    : Response::HTTP_INTERNAL_SERVER_ERROR;
            }
            $code = match ($status) {
                400 => 'bad_request',
                401 => 'unauthorized',
                403 => 'forbidden',
                404 => 'not_found',
                405 => 'method_not_allowed',
                422 => 'validation_failed',
                429 => 'too_many_requests',
                503 => 'service_unavailable',
                default => $status >= 500 ? 'internal_error' : 'http_error',
            };
            if ($exception instanceof DomainException) {
                $code = 'domain_rule_violated';
                // Domain messages describe internals ("Malformed exchange
                // rate ...") — keep them out of the response body.
                $message = 'The request violates a business rule.';
                $this->logger->warning('Domain rule violated: {message}', [
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]);
            } else {
                $message = Response::$statusTexts[$status] ?? 'Request failed';
            }

            $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];
        }

        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ([] !== $fields) {
            $error['fields'] = $fields;
        }

        $headers['Cache-Control'] = 'no-store';
        $event->setResponse(new JsonResponse(['error' => $error], $status, $headers));
    }
}
