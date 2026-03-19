<?php

namespace Searchology;

use RuntimeException;

class SearchologyException extends RuntimeException
{
    private int    $statusCode;
    private string $errorCode;

    public function __construct(string $message, int $statusCode = 0, string $errorCode = 'unknown_error')
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->errorCode  = $errorCode;
    }

    /**
     * HTTP status code returned by the API (401, 429, 500, etc.)
     * Returns 0 for local validation errors or connection failures.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Machine-readable error code from the API.
     * e.g. 'unauthorized', 'too_many_requests', 'extraction_failed'
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}