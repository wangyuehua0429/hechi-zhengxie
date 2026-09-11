<?php

declare(strict_types=1);

namespace HechiZx\Admin;

/**
 * 一次性提示：保存成功/失败后在跳转目标页显示一条。
 */
final class Flash
{
    private const KEY = '_flash';

    public static function set(string $type, string $text): void
    {
        $_SESSION[self::KEY] = ['type' => $type, 'text' => $text];
    }

    /** @return array{type:string,text:string}|null */
    public static function take(): ?array
    {
        $value = $_SESSION[self::KEY] ?? null;
        unset($_SESSION[self::KEY]);
        return is_array($value) ? $value : null;
    }
}
