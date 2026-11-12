<?php

namespace App\Domain\Licensing;

final readonly class ActivationResult
{
    public function __construct(
        public bool $ok,
        public int $httpStatus,
        public string $code,
        public string $message,
        public ?array $body = null,
    ) {}

    public static function success(array $body): self
    {
        return new self(true, 200, 'OK', 'Activated', $body);
    }

    public static function failure(int $status, string $code, string $message, ?array $body = null): self
    {
        return new self(false, $status, $code, $message, $body);
    }
}
