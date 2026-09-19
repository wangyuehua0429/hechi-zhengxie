<?php

declare(strict_types=1);

namespace HechiZx\Content;

/**
 * 公开口径开关：决定「已发布」之外还要不要按 public_scope 过滤。
 *
 * 站点级配置见 config.php 的 content.enforce_public_scope：
 *   true  ＝ 只出 public_scope=public：超出公开年限的 archive 稿只留后台，
 *            不产静态页、不登记 301、不进 sitemap；
 *   false ＝ 放开年限，status=published 的稿件一律对外（当前默认）。
 *
 * 对外出口（首页、栏目页、详情页、站内检索、静态发布、旧地址 301）都从这里取条件，
 * 避免有的地方放开、有的地方还挡着。口径说明见 docs/api-contract.md 第 2 节。
 */
final class PublicScope
{
    private static ?bool $enforced = null;

    /** 是否启用 public_scope 过滤（配置读一次后缓存） */
    public static function enforced(): bool
    {
        return self::$enforced ??= (bool) hechi_config('content.enforce_public_scope', true);
    }

    /**
     * 可直接拼进 SQL 的公开口径条件：放开年限时退化成恒真，调用方不必再多绑参数
     * （留一个用不到的占位符/绑定值，在 MySQL 上会报 HY093）。
     *
     * @param string $alias cms_article 在该语句里的别名；语句里没写别名时留空
     */
    public static function sql(string $alias = ''): string
    {
        if (!self::enforced()) {
            return '1 = 1';
        }
        return ($alias === '' ? '' : $alias . '.') . "public_scope = 'public'";
    }

    /** 单条稿件的 public_scope 是否算对外（出口逐条判断可见性时用） */
    public static function allows(?string $scope): bool
    {
        return !self::enforced() || $scope === 'public';
    }
}
