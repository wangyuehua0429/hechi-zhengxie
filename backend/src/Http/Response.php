<?php

declare(strict_types=1);

namespace HechiZx\Http;

use HechiZx\Support\Json;

/**
 * 响应输出：统一 JSON 头，错误体固定为 {"error":{"code":..,"message":..}}（见 docs/api-contract.md 第 2 节）。
 */
final class Response
{
    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200, int $cacheMaxAge = 0): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        if ($cacheMaxAge > 0) {
            header('Cache-Control: public, max-age=' . $cacheMaxAge);
        } else {
            header('Cache-Control: no-store');
        }
        echo Json::encode($data);
    }

    public static function error(string $code, string $message, int $status): void
    {
        self::json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
