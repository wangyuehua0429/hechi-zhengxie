<?php

declare(strict_types=1);

namespace HechiZx\Http;

/**
 * 二进制下载响应（导出的 Word／Excel、提案附件）。
 * 文件名同时给 ASCII 回退与 RFC 5987 的 filename*，中文名在浏览器里不乱码。
 */
final class FileResponse
{
    public function __construct(
        private string $body,
        private string $contentType,
        private string $filename,
        private int $status = 200
    ) {
    }

    public static function fromPath(string $path, string $contentType, string $filename): self
    {
        return new self((string) file_get_contents($path), $contentType, $filename);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: ' . $this->contentType);
        header('Content-Length: ' . strlen($this->body));
        header('Content-Disposition: attachment; filename="' . self::asciiFallback($this->filename) . '"; '
            . "filename*=UTF-8''" . rawurlencode($this->filename));
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src \'none\'');
        header('Cache-Control: no-store');
        echo $this->body;
    }

    /** 非 ASCII 字符在 filename= 里换成下划线，真正的名字走 filename* */
    private static function asciiFallback(string $filename): string
    {
        $fallback = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?? 'download';
        $fallback = str_replace(['"', '\\'], '_', $fallback);
        return $fallback === '' ? 'download' : $fallback;
    }
}
