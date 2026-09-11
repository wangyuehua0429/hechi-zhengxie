<?php

declare(strict_types=1);

namespace HechiZx\Http;

/**
 * 跳转响应：表单提交成功后回列表或回编辑页用。
 */
final class RedirectResponse
{
    public function __construct(private string $location, private int $status = 302)
    {
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Location: ' . $this->location);
    }

    public function location(): string
    {
        return $this->location;
    }
}
