<?php

declare(strict_types=1);

namespace HechiZx\Content;

/**
 * 权限码清单：代码里固定，管理页只做勾选，不允许在界面上新造权限码。
 * 与 docs/用户分组与权限设计.md 第 4.3 节一一对应。
 */
final class Permissions
{
    public const ARTICLE_EDIT = 'article.edit';
    public const ARTICLE_SUBMIT = 'article.submit';
    public const ARTICLE_REVIEW = 'article.review';
    public const ARTICLE_PUBLISH = 'article.publish';
    public const ARTICLE_DELETE = 'article.delete';
    public const ARTICLE_RESTORE = 'article.restore';
    public const ARTICLE_PURGE = 'article.purge';
    public const CHANNEL_MANAGE = 'channel.manage';
    public const HOME_MANAGE = 'home.manage';
    public const PUBLISH_RUN = 'publish.run';
    public const USER_MANAGE = 'user.manage';
    public const LOG_VIEW = 'log.view';

    /**
     * 分组只为后台勾选界面的排版服务，不影响鉴权。
     *
     * @return array<string, array<string, string>> 分组名 => [权限码 => 说明]
     */
    public static function groups(): array
    {
        return [
            '稿件' => [
                self::ARTICLE_EDIT    => '新建与编辑稿件（范围受栏目限制）',
                self::ARTICLE_SUBMIT  => '提交审核',
                self::ARTICLE_REVIEW  => '审核通过或退回',
                self::ARTICLE_PUBLISH => '发布、撤回、重新发布',
                self::ARTICLE_DELETE  => '移入回收站',
                self::ARTICLE_RESTORE => '从回收站恢复',
                self::ARTICLE_PURGE   => '物理清除（不可恢复，仅管理员）',
            ],
            '栏目与发布' => [
                self::CHANNEL_MANAGE => '栏目信息、版式与排序维护',
                self::HOME_MANAGE    => '首页导航、头条轮换、其他栏目与站内横幅维护',
                self::PUBLISH_RUN    => '生成静态页与数据快照',
            ],
            '系统' => [
                self::USER_MANAGE => '用户与角色管理',
                self::LOG_VIEW    => '操作日志查看',
            ],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        $codes = [];
        foreach (self::groups() as $items) {
            foreach (array_keys($items) as $code) {
                $codes[] = (string) $code;
            }
        }
        return $codes;
    }

    public static function label(string $code): string
    {
        foreach (self::groups() as $items) {
            if (isset($items[$code])) {
                return (string) $items[$code];
            }
        }
        return $code;
    }
}
