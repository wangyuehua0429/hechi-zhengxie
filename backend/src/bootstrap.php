<?php

/**
 * 极简引导：PSR-4 自动加载（HechiZx\ → backend/src/）+ 配置装载。
 * 本期不引 Composer，保持「零依赖即可跑」；后续要引第三方包时再加 composer.json。
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'HechiZx\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use HechiZx\Support\Config;

/**
 * 取配置：hechi_config('db.driver')，不传参数返回整个配置对象。
 */
function hechi_config(?string $key = null, mixed $default = null): mixed
{
    static $config = null;
    if ($config === null) {
        $config = Config::load(dirname(__DIR__) . '/config/config.php');
    }
    return $key === null ? $config : $config->get($key, $default);
}

/**
 * 模板里的转义助手：所有输出到 HTML 的变量都过它。
 */
function hechi_e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * 后台页面在 /admin/ 下，`images/x.jpg` 这种相对地址会被解析成 /admin/images/x.jpg。
 * 站点素材统一补上根路径；已经是绝对地址、协议相对或 data: 的原样返回。
 */
function hechi_asset(mixed $url): string
{
    $url = trim((string) $url);
    if ($url === '' || str_starts_with($url, '/') || str_starts_with($url, 'data:')
        || preg_match('#^(https?:)?//#i', $url) === 1) {
        return $url;
    }
    return '/' . ltrim($url, '/');
}

/*
 * 统一时区：CLI 脚本与 Web 入口都从这里走，避免 date() 用 UTC、
 * 而数据库的 datetime('now','localtime') 用本地时间，两边差 8 小时。
 */
date_default_timezone_set((string) hechi_config('app.timezone', 'Asia/Shanghai'));
