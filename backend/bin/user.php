#!/usr/bin/env php
<?php

/**
 * 后台账号管理（命令行，不入口 Web）。
 *
 *   php backend/bin/user.php list
 *   php backend/bin/user.php create <账号> <密码> [真实姓名]
 *   php backend/bin/user.php passwd <账号> <新密码>
 *   php backend/bin/user.php disable <账号>
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use HechiZx\Support\Db;

$argvCopy = $argv;
array_shift($argvCopy);
$command = $argvCopy[0] ?? 'help';

$db = new Db((array) hechi_config('db'));

$usage = <<<TXT
用法：
  php backend/bin/user.php list
  php backend/bin/user.php create <账号> <密码> [真实姓名]
  php backend/bin/user.php passwd <账号> <新密码>
  php backend/bin/user.php disable <账号>
TXT;

switch ($command) {
    case 'list':
        $rows = $db->select('SELECT user_id, username, real_name, status, last_login_at FROM sys_user ORDER BY user_id');
        if ($rows === []) {
            fwrite(STDOUT, "还没有后台账号，用 create 命令建一个。\n");
            break;
        }
        fwrite(STDOUT, sprintf("%-4s %-14s %-12s %-10s %s\n", 'ID', '账号', '姓名', '状态', '最后登录'));
        foreach ($rows as $row) {
            fwrite(STDOUT, sprintf(
                "%-4d %-14s %-12s %-10s %s\n",
                (int) $row['user_id'],
                (string) $row['username'],
                (string) $row['real_name'],
                (string) $row['status'],
                (string) ($row['last_login_at'] ?? '—')
            ));
        }
        break;

    case 'create':
        $username = $argvCopy[1] ?? '';
        $password = $argvCopy[2] ?? '';
        $realName = $argvCopy[3] ?? '';
        if ($username === '' || $password === '') {
            fwrite(STDERR, "账号与密码不能为空。\n" . $usage . "\n");
            exit(1);
        }
        if (strlen($password) < 8) {
            fwrite(STDERR, "密码至少 8 位。\n");
            exit(1);
        }
        if ($db->scalar('SELECT user_id FROM sys_user WHERE username = :u', ['u' => $username]) !== null) {
            fwrite(STDERR, "账号已存在：" . $username . "（改密码用 passwd）。\n");
            exit(1);
        }
        $db->execute(
            'INSERT INTO sys_user (username, password_hash, real_name, status, created_at, updated_at)
             VALUES (:u, :p, :n, :s, :t, :t)',
            [
                'u' => $username,
                'p' => password_hash($password, PASSWORD_DEFAULT),
                'n' => $realName,
                's' => 'enabled',
                't' => date('Y-m-d H:i:s'),
            ]
        );
        fwrite(STDOUT, "已创建后台账号：" . $username . ($realName !== '' ? "（" . $realName . "）" : '') . "\n");
        break;

    case 'passwd':
        $username = $argvCopy[1] ?? '';
        $password = $argvCopy[2] ?? '';
        if ($username === '' || strlen($password) < 8) {
            fwrite(STDERR, "用法：php backend/bin/user.php passwd <账号> <新密码>（至少 8 位）\n");
            exit(1);
        }
        $affected = $db->execute(
            'UPDATE sys_user SET password_hash = :p, updated_at = :t WHERE username = :u',
            ['p' => password_hash($password, PASSWORD_DEFAULT), 't' => date('Y-m-d H:i:s'), 'u' => $username]
        );
        fwrite(STDOUT, $affected > 0 ? "已更新密码：" . $username . "\n" : "没找到账号：" . $username . "\n");
        break;

    case 'disable':
        $username = $argvCopy[1] ?? '';
        if ($username === '') {
            fwrite(STDERR, "用法：php backend/bin/user.php disable <账号>\n");
            exit(1);
        }
        $affected = $db->execute(
            'UPDATE sys_user SET status = :s, updated_at = :t WHERE username = :u',
            ['s' => 'disabled', 't' => date('Y-m-d H:i:s'), 'u' => $username]
        );
        fwrite(STDOUT, $affected > 0 ? "已停用：" . $username . "\n" : "没找到账号：" . $username . "\n");
        break;

    default:
        fwrite(STDOUT, $usage . "\n");
}
