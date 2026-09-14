<?php

declare(strict_types=1);

namespace HechiZx\Content;

/**
 * 委员提案流转：把「状态 → 允许的动作」集中在一处，控制器、模板与仓储都从这里取。
 * 设计说明见 docs/提案系统设计说明.md 第 2 节。
 *
 * 本期只做三态（提交与提案委受理），交办与答复仍走线下：
 *   已提交 submitted → 已受理 accepted ／ 已退回 returned；退回后委员可改稿重交，状态回到已提交。
 * 状态机不查库、不鉴权，只回答「这个状态能不能做这个动作、做完到哪个状态」。
 */
final class ProposalWorkflow
{
    public const SUBMITTED = 'submitted';
    public const ACCEPTED = 'accepted';
    public const RETURNED = 'returned';

    /** @return array<string, string> 状态值 => 中文名 */
    public static function places(): array
    {
        return [
            self::SUBMITTED => '已提交',
            self::ACCEPTED  => '已受理',
            self::RETURNED  => '已退回',
        ];
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return array_keys(self::places());
    }

    public static function label(string $status): string
    {
        return self::places()[$status] ?? $status;
    }

    /** 未知状态按已提交处理，避免历史脏值把页面打空 */
    public static function normalize(string $status): string
    {
        return isset(self::places()[$status]) ? $status : self::SUBMITTED;
    }

    /** @return list<string> 提案类别：五位一体 + 其他 */
    public static function categories(): array
    {
        return ['经济建设', '政治建设', '文化建设', '社会建设', '生态文明建设', '其他'];
    }

    /** @return array<string, string> 提案人类别 */
    public static function proposerTypes(): array
    {
        return [
            'personal'   => '个人',
            'joint'      => '联名',
            'collective' => '集体',
        ];
    }

    public static function proposerTypeLabel(string $type): string
    {
        return self::proposerTypes()[$type] ?? $type;
    }

    /** 只有被退回的提案可以改稿 */
    public static function canMemberEdit(string $status): bool
    {
        return self::normalize($status) === self::RETURNED;
    }

    /**
     * 提案委侧的动作表：perm 为权限码，needNote 表示必须填写意见。
     *
     * @return array<string, array{label:string, from:list<string>, to:string, perm:string, needNote:bool, noteField:string, noteLabel:string, danger:bool}>
     */
    public static function transitions(): array
    {
        return [
            'accept' => [
                'label' => '受理', 'from' => [self::SUBMITTED], 'to' => self::ACCEPTED,
                'perm' => Permissions::PROPOSAL_REVIEW, 'needNote' => false,
                'noteField' => 'review_note', 'noteLabel' => '受理意见', 'danger' => false,
            ],
            'return' => [
                'label' => '退回补充', 'from' => [self::SUBMITTED, self::ACCEPTED], 'to' => self::RETURNED,
                'perm' => Permissions::PROPOSAL_REVIEW, 'needNote' => true,
                'noteField' => 'returned_reason', 'noteLabel' => '退回意见', 'danger' => true,
            ],
        ];
    }

    /**
     * 提案委在当前状态下可执行的动作（先过状态机，再按权限位过滤）。
     *
     * @param callable(string):bool $can
     * @return list<string>
     */
    public static function allowedActions(string $status, callable $can): array
    {
        $current = self::normalize($status);
        $allowed = [];
        foreach (self::transitions() as $action => $rule) {
            if (in_array($current, $rule['from'], true) && $can($rule['perm'])) {
                $allowed[] = (string) $action;
            }
        }
        return $allowed;
    }

    /** 流转记录里的动作名 */
    public static function actionLabel(string $action): string
    {
        $labels = [
            'submit'   => '提交提案',
            'resubmit' => '修改后重新提交',
            'accept'   => '受理',
            'return'   => '退回补充',
        ];
        if (isset($labels[$action])) {
            return $labels[$action];
        }
        return $action;
    }
}
