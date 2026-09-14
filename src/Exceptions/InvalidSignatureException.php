<?php

namespace Shamimstack\AssetShield\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Thrown when a signed/expiring asset URL fails verification. The controller
 * converts this into an HTTP 403 Forbidden response.
 */
class InvalidSignatureException extends RuntimeException implements HttpExceptionInterface
{
    public function __construct(string $message = 'AssetShield rejected an invalid or expired signature.')
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return 403;
    }

    public function getHeaders(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}