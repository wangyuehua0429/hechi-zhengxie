<?php

declare(strict_types=1);

namespace HechiZx\Publish;

/**
 * 静态化产物的路径规则。
 *
 * 栏目页路径不能直接用 channel.json 的 slug：slug 是**一级栏目**的标识，
 * 902—906（政协动态的五个子栏目）都写着 `zhengxie-dongtai`，601—607（党派团体
 * 七个子栏目）都写着 `dangpai-tuanti`。直接拿它当目录名，后写的栏目会覆盖先写的，
 * 43 个栏目只剩 25 个静态页，sitemap 里还会出现重复地址。
 *
 * 规则：slug 在全部栏目里唯一时用 `/channel/<slug>/`；重复时给这一组都补上栏目号，
 * 写成 `/channel/<slug>-<栏目号>/`。两种写法都只依赖库里已有的字段，结果稳定、可预期，
 * 重新发布不会换来换去（301 映射表也用这里的结果，见 RedirectMap）。
 */
final class StaticPaths
{
    /**
     * @param list<array<string, mixed>> $channels 栏目数据（含 type / slug）
     * @return array<string, string> 栏目号 => 以 / 开头与结尾的栏目页地址
     */
    public static function channelPaths(array $channels): array
    {
        $counts = [];
        foreach ($channels as $channel) {
            $key = self::baseSegment($channel);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $paths = [];
        foreach ($channels as $channel) {
            $key = self::baseSegment($channel);
            if ($counts[$key] > 1) {
                $key .= '-' . (string) $channel['type'];
            }
            $paths[(string) $channel['type']] = '/channel/' . $key . '/';
        }
        return $paths;
    }

    /**
     * 目录名片段：slug 为空时退回栏目号，非 URL 安全字符换成连字符。
     *
     * @param array<string, mixed> $channel
     */
    public static function baseSegment(array $channel): string
    {
        $slug = trim((string) ($channel['slug'] ?? ''));
        $segment = preg_replace('/[^A-Za-z0-9._-]+/', '-', $slug) ?? '';
        $segment = trim($segment, '-');
        return $segment !== '' ? $segment : (string) $channel['type'];
    }
}
