<?php

declare(strict_types=1);

namespace HechiZx\Http;

use RuntimeException;

/**
 * 接口异常：带 HTTP 状态码与错误码，由入口统一转成错误响应。
 */
final class ApiException extends RuntimeException
{
    public function __construct(
        private string $errorCode,
        string $message,
        private int $status = 400
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message): self
    {
        return new self('not_found', $message, 404);
    }

    public static function badRequest(string $message): self
    {
        return new self('bad_request', $message, 400);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }
}
