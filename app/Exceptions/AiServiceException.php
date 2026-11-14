<?php

namespace App\Exceptions;

use RuntimeException;

class AiServiceException extends RuntimeException
{
    public function __construct(
        string $message,
        protected int $httpStatus = 502
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
