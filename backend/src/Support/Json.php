<?php

declare(strict_types=1);

namespace HechiZx\Support;

/**
 * JSON 编解码：统一不转义中文与斜杠，保证接口输出与阶段 A 的快照文件可比对。
 */
final class Json
{
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public static function encode(mixed $value): string
    {
        $json = json_encode($value, self::FLAGS);
        if ($json === false) {
            throw new \RuntimeException('JSON 编码失败：' . json_last_error_msg());
        }
        return $json;
    }

    public static function decode(?string $json, mixed $default = null): mixed
    {
        if ($json === null || $json === '') {
            return $default;
        }
        $value = json_decode($json, true);
        return $value === null ? $default : $value;
    }

    public static function readFile(string $file): mixed
    {
        if (!is_file($file)) {
            throw new \RuntimeException('数据文件不存在：' . $file);
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new \RuntimeException('数据文件读取失败：' . $file);
        }
        return self::decode($raw);
    }
}
