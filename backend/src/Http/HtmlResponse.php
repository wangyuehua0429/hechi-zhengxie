<?php

declare(strict_types=1);

namespace HechiZx\Http;

/**
 * HTML 响应：后台页面走这里，接口仍走 Response::json。
 */
final class HtmlResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        private string $body,
        private int $status = 200,
        private array $headers = []
    ) {
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: text/html; charset=utf-8');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
