<?php

declare(strict_types=1);

namespace HechiZx\Http;

use HechiZx\Publish\RedirectMap;

/**
 * 旧地址 301 的运行期判定：请求在站点里找不到对应文件时，查 sys_url_redirect，
 * 命中就 301 到新地址并累计命中数，没命中照旧 404。
 *
 * 只处理 GET：POST 到旧脚本的请求（旧站没有这种用法）不该被改成 301，那样会把
 * 请求体丢掉。映射明细由 `php backend/bin/redirects.php` 生成，见 RedirectMap。
 */
final class LegacyRedirect
{
    public function __construct(private RedirectMap $map)
    {
    }

    public function handle(Request $request): ?RedirectResponse
    {
        if ($request->method() !== 'GET') {
            return null;
        }

        $hit = $this->map->resolve(
            $request->path(),
            (string) ($request->query('id') ?? ''),
            (string) ($request->query('q') ?? '')
        );
        if ($hit === null) {
            return null;
        }

        $this->map->touch($hit['key']);
        $status = in_array($hit['status'], [301, 302, 308], true) ? $hit['status'] : 301;
        return new RedirectResponse($hit['target'], $status);
    }
}
