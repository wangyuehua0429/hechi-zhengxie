<?php

declare(strict_types=1);

namespace HechiZx\Http;

/**
 * HTML 响应：后台页面走这里，接口仍走 Response::json。
 */
final class HtmlResponse
{
    /**
     * 后台页面统一安全响应头（纵深防御，正文白名单清洗之外的兜底）。
     *
     * script-src 不放 'unsafe-inline'，登录页那段内联脚本靠 sha256 哈希放行——
     * 后台只有这一处内联脚本、没有内联事件属性，改 `backend/templates/admin/login.php`
     * 的内联脚本后必须同步更新下面的哈希，`tests/sanitize-check.mjs` 会核对两者一致。
     *
     * style-src 需要 'unsafe-inline'：后台模板与正文对齐都使用内联 style 属性。
     * img-src 放 data: 供界面上的内联小图标使用，正文里的 data: 图片仍会被白名单清洗掉。
     */
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "default-src 'self'; "
            . "img-src 'self' data:; "
            . "style-src 'self' 'unsafe-inline'; "
            . "script-src 'self' 'sha256-RFMm59wLa4HykiV1rd+OyNaTbcj0HVfSUZL+nJQ/zR0='; "
            . "frame-src 'self'; "
            . "object-src 'none'; "
            . "base-uri 'none'",
    ];

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
        // 调用方传入的同名头优先，便于个别页面覆盖默认策略
        foreach (array_merge(self::SECURITY_HEADERS, $this->headers) as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
