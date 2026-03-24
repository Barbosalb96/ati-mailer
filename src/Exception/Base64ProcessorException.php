<?php

namespace Atima\ApiEmailLib\Exception;

class Base64ProcessorException extends \RuntimeException
{
    public const REASON_INVALID_BASE64   = 'invalid_base64';
    public const REASON_MIME_NOT_ALLOWED = 'mime_not_allowed';
    public const REASON_TOO_LARGE        = 'too_large';
    public const REASON_WRITE_ERROR      = 'write_error';

    public function __construct(
        private readonly string $reason,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
