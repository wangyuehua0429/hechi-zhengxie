<?php

declare(strict_types=1);

namespace HechiZx\Content;

/**
 * 稿件稿库状态机：把「稿库 → 状态 → 允许的流转」集中在一处，
 * 控制器、模板与仓储都从这里取，避免状态值散落各文件。
 * 设计与流转表见 docs/稿库与内容状态设计.md 第 2、3 节。
 *
 * 状态机不查库、不鉴权，只回答「这个状态能不能做这个动作、做完到哪个状态」；
 * 权限与数据范围由 Auth 和控制器判断。
 */
final class ArticleWorkflow
{
    public const DRAFT = 'draft';
    public const PENDING = 'pending';
    public const REJECTED = 'rejected';
    public const PUBLISHED = 'published';
    public const WITHDRAWN = 'withdrawn';
    public const DELETED = 'deleted';

    /** 兼容旧值：offline 语义等同已撤回 */
    public const LEGACY_OFFLINE = 'offline';

    /**
     * 稿库定义。
     *
     * @return array<string, array{status:string, label:string, public:bool, sort:int}>
     */
    public static function places(): array
    {
        return [
            'draft'     => ['status' => self::DRAFT, 'label' => '草稿', 'public' => false, 'sort' => 10],
            'pending'   => ['status' => self::PENDING, 'label' => '待审', 'public' => false, 'sort' => 20],
            'rejected'  => ['status' => self::REJECTED, 'label' => '退回', 'public' => false, 'sort' => 30],
            'published' => ['status' => self::PUBLISHED, 'label' => '已发布', 'public' => true, 'sort' => 40],
            'withdrawn' => ['status' => self::WITHDRAWN, 'label' => '已撤回', 'public' => false, 'sort' => 50],
            'deleted'   => ['status' => self::DELETED, 'label' => '回收站', 'public' => false, 'sort' => 60],
        ];
    }

    /**
     * 状态流转表。perm 为主权限码，altPerm 为「有此权限也行」（待审通过既可由审核岗，也可由发布岗直接发）。
     *
     * @return array<string, array{
     *     label:string, from:list<string>, to:string, perm:string, altPerm:string,
     *     needNote:bool, noteField:string, noteLabel:string, danger:bool
     * }>
     */
    public static function transitions(): array
    {
        return [
            'submit' => [
                'label' => '提交审核', 'from' => [self::DRAFT, self::REJECTED], 'to' => self::PENDING,
                'perm' => Permissions::ARTICLE_SUBMIT, 'altPerm' => '', 'needNote' => false,
                'noteField' => '', 'noteLabel' => '', 'danger' => false,
            ],
            'approve' => [
                'label' => '审核通过', 'from' => [self::PENDING], 'to' => self::PUBLISHED,
                'perm' => Permissions::ARTICLE_REVIEW, 'altPerm' => Permissions::ARTICLE_PUBLISH, 'needNote' => false,
                'noteField' => '', 'noteLabel' => '', 'danger' => false,
            ],
            'reject' => [
                'label' => '退回', 'from' => [self::PENDING], 'to' => self::REJECTED,
                'perm' => Permissions::ARTICLE_REVIEW, 'altPerm' => '', 'needNote' => true,
                'noteField' => 'review_note', 'noteLabel' => '退回意见', 'danger' => false,
            ],
            'withdraw' => [
                'label' => '撤回', 'from' => [self::PUBLISHED], 'to' => self::WITHDRAWN,
                'perm' => Permissions::ARTICLE_PUBLISH, 'altPerm' => '', 'needNote' => true,
                'noteField' => 'withdraw_reason', 'noteLabel' => '撤回原因', 'danger' => true,
            ],
            'republish' => [
                'label' => '重新发布', 'from' => [self::WITHDRAWN, self::LEGACY_OFFLINE], 'to' => self::PUBLISHED,
                'perm' => Permissions::ARTICLE_PUBLISH, 'altPerm' => '', 'needNote' => false,
                'noteField' => '', 'noteLabel' => '', 'danger' => false,
            ],
            'delete' => [
                'label' => '移入回收站',
                'from' => [self::DRAFT, self::PENDING, self::REJECTED, self::WITHDRAWN, self::LEGACY_OFFLINE],
                'to' => self::DELETED,
                'perm' => Permissions::ARTICLE_DELETE, 'altPerm' => '', 'needNote' => false,
                'noteField' => '', 'noteLabel' => '', 'danger' => true,
            ],
            'restore' => [
                'label' => '恢复', 'from' => [self::DELETED], 'to' => '',
                'perm' => Permissions::ARTICLE_RESTORE, 'altPerm' => '', 'needNote' => false,
                'noteField' => '', 'noteLabel' => '', 'danger' => false,
            ],
        ];
    }

    /** 旧值归位：offline 按已撤回处理，其它原样返回 */
    public static function normalize(string $status): string
    {
        return $status === self::LEGACY_OFFLINE ? self::WITHDRAWN : $status;
    }

    public static function label(string $status): string
    {
        $status = self::normalize($status);
        foreach (self::places() as $place) {
            if ($place['status'] === $status) {
                return $place['label'];
            }
        }
        return $status;
    }

    /** @return list<string> 全部状态值（含兼容值），供筛选白名单用 */
    public static function statuses(): array
    {
        $statuses = [];
        foreach (self::places() as $place) {
            $statuses[] = $place['status'];
        }
        $statuses[] = self::LEGACY_OFFLINE;
        return $statuses;
    }

    /** @return array<string, mixed>|null */
    public static function transition(string $action): ?array
    {
        return self::transitions()[$action] ?? null;
    }

    /**
     * 该状态下允许执行的动作清单（只判状态，不判权限）。
     *
     * @return list<string>
     */
    public static function actionsFor(string $status): array
    {
        $status = self::normalize($status);
        $actions = [];
        foreach (self::transitions() as $action => $rule) {
            if (in_array($status, $rule['from'], true)) {
                $actions[] = $action;
            }
        }
        return $actions;
    }

    /**
     * 流转目标状态。restore 的目标由调用方按 status_before_delete 传入。
     */
    public static function target(string $action, string $status, string $statusBeforeDelete = ''): ?string
    {
        $rule = self::transition($action);
        if ($rule === null || !in_array(self::normalize($status), $rule['from'], true)) {
            return null;
        }
        if ($action === 'restore') {
            $back = self::normalize($statusBeforeDelete);
            return in_array($back, [self::DRAFT, self::PENDING, self::REJECTED, self::PUBLISHED, self::WITHDRAWN], true)
                ? $back
                : self::DRAFT;
        }
        return (string) $rule['to'];
    }
}
