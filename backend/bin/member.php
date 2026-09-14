#!/usr/bin/env php
<?php

/**
 * 委员账号管理（命令行，不入口 Web）。
 *
 * 正式做法是提案委在后台 /admin/members/import 导入委员名册；这条命令用于本机调试、
 * 补开单个账号、以及忘记密码时的应急处置。
 *
 *   php backend/bin/member.php list
 *   php backend/bin/member.php create <登录名> <密码> [姓名] [界别] [专委会] [手机号]
 *   php backend/bin/member.php passwd <登录名> <新密码>
 *   php backend/bin/member.php reset <登录名>
 *   php backend/bin/member.php disable <登录名>
 *   php backend/bin/member.php enable <登录名>
 *
 * 与后台账号（bin/user.php）一样：密码用 password_hash 存，不落明文；
 * create／passwd／reset 都会把 must_change_password 置 1，委员首次登录必须改密。
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use HechiZx\Proposal\MemberImporter;
use HechiZx\Support\Db;

$args = $argv;
array_shift($args);
$command = $args[0] ?? 'help';

$db = new Db((array) hechi_config('db'));

$usage = <<<TXT
用法：
  php backend/bin/member.php list
  php backend/bin/member.php create <登录名> <密码> [姓名] [界别] [专委会] [手机号]
  php backend/bin/member.php passwd <登录名> <新密码>
  php backend/bin/member.php reset <登录名>
  php backend/bin/member.php disable <登录名>
  php backend/bin/member.php enable <登录名>

登录名默认取手机号；创建后须在提案门户 http://<域名>/member 登录，首次登录会要求改密。
TXT;

/** 取委员行，找不到时打印提示并退出 */
$requireMember = static function (Db $db, string $login): array {
    $row = $db->selectOne('SELECT * FROM sys_member WHERE login_name = :l', ['l' => $login]);
    if ($row === null) {
        fwrite(STDERR, '没找到委员账号：' . $login . "\n");
        exit(1);
    }
    return $row;
};

switch ($command) {
    case 'list':
        $rows = $db->select(
            'SELECT m.member_id, m.name, m.login_name, m.mobile, m.sector, m.committee, m.status,
                    m.must_change_password, m.last_login_at,
                    (SELECT COUNT(*) FROM cms_proposal p WHERE p.member_id = m.member_id) AS proposals
             FROM sys_member m ORDER BY m.member_id'
        );
        if ($rows === []) {
            fwrite(STDOUT, "还没有委员账号。用 create 建一个，或在后台 /admin/members/import 导入名册。\n");
            break;
        }
        foreach ($rows as $row) {
            printf(
                "%-4s %-10s %-14s %-14s %-8s %-8s 提案 %-3s %s%s\n",
                $row['member_id'],
                (string) $row['name'],
                (string) $row['login_name'],
                (string) $row['mobile'],
                (string) ($row['sector'] !== '' ? $row['sector'] : '—'),
                (string) ($row['committee'] !== '' ? $row['committee'] : '—'),
                $row['proposals'],
                (string) $row['status'] === 'enabled' ? '启用' : '停用',
                (int) $row['must_change_password'] === 1 ? '（待首登改密）' : ''
            );
        }
        break;

    case 'create':
        $login = $args[1] ?? '';
        $password = $args[2] ?? '';
        if ($login === '' || $password === '') {
            fwrite(STDERR, $usage . "\n");
            exit(1);
        }
        if (mb_strlen($password) < 8) {
            fwrite(STDERR, "密码至少 8 位。\n");
            exit(1);
        }
        if ($db->scalar('SELECT member_id FROM sys_member WHERE login_name = :l', ['l' => $login]) !== null) {
            fwrite(STDERR, '登录名已存在：' . $login . "\n");
            exit(1);
        }
        $now = $db->now();
        $db->execute(
            'INSERT INTO sys_member (name, login_name, password_hash, must_change_password, mobile, sector,
               committee, org_title, term, status, remark, created_at, updated_at)
             VALUES (:name, :login, :hash, 1, :mobile, :sector, :committee, :org, :term, :status, :remark, :t, :t)',
            [
                'name'      => $args[3] ?? $login,
                'login'     => $login,
                'hash'      => password_hash($password, PASSWORD_DEFAULT),
                'mobile'    => $args[6] ?? '',
                'sector'    => $args[4] ?? '',
                'committee' => $args[5] ?? '',
                'org'       => '',
                'term'      => '',
                'status'    => 'enabled',
                'remark'    => '命令行创建',
                't'         => $now,
            ]
        );
        fwrite(STDOUT, '已创建委员账号：' . $login . '（' . ($args[3] ?? $login) . "）\n");
        fwrite(STDOUT, "请到提案门户登录，首次登录会要求修改密码。\n");
        break;

    case 'passwd':
        $login = $args[1] ?? '';
        $password = $args[2] ?? '';
        if ($login === '' || $password === '') {
            fwrite(STDERR, $usage . "\n");
            exit(1);
        }
        if (mb_strlen($password) < 8) {
            fwrite(STDERR, "密码至少 8 位。\n");
            exit(1);
        }
        $member = $requireMember($db, $login);
        $db->execute(
            'UPDATE sys_member SET password_hash = :h, must_change_password = 1, failed_attempts = 0,
               locked_until = NULL, updated_at = :t WHERE member_id = :id',
            ['h' => password_hash($password, PASSWORD_DEFAULT), 't' => $db->now(), 'id' => (int) $member['member_id']]
        );
        fwrite(STDOUT, '已重设密码：' . $login . "（首次登录仍会要求改密）\n");
        break;

    case 'reset':
        $login = $args[1] ?? '';
        if ($login === '') {
            fwrite(STDERR, $usage . "\n");
            exit(1);
        }
        $member = $requireMember($db, $login);
        $password = MemberImporter::randomPassword();
        $db->execute(
            'UPDATE sys_member SET password_hash = :h, must_change_password = 1, failed_attempts = 0,
               locked_until = NULL, updated_at = :t WHERE member_id = :id',
            ['h' => password_hash($password, PASSWORD_DEFAULT), 't' => $db->now(), 'id' => (int) $member['member_id']]
        );
        fwrite(STDOUT, '已生成新密码：' . $login . ' / ' . $password . "\n");
        fwrite(STDOUT, "该密码只显示这一次，请当场告知本人；首次登录会要求改密。\n");
        break;

    case 'disable':
    case 'enable':
        $login = $args[1] ?? '';
        if ($login === '') {
            fwrite(STDERR, $usage . "\n");
            exit(1);
        }
        $member = $requireMember($db, $login);
        $status = $command === 'disable' ? 'disabled' : 'enabled';
        $db->execute(
            'UPDATE sys_member SET status = :s, updated_at = :t WHERE member_id = :id',
            ['s' => $status, 't' => $db->now(), 'id' => (int) $member['member_id']]
        );
        fwrite(STDOUT, ($command === 'disable' ? '已停用：' : '已启用：') . $login . "\n");
        break;

    default:
        fwrite(STDOUT, $usage . "\n");
        break;
}
