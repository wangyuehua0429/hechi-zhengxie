#!/usr/bin/env php
<?php

/**
 * 后台账号管理（命令行，不入口 Web）。
 *
 *   php backend/bin/user.php list
 *   php backend/bin/user.php create <账号> <密码> [真实姓名] [角色代码]
 *   php backend/bin/user.php passwd <账号> <新密码>
 *   php backend/bin/user.php disable <账号>
 *   php backend/bin/user.php enable <账号>
 *   php backend/bin/user.php roles
 *   php backend/bin/user.php role <账号> <角色代码> [角色代码...]
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
  php backend/bin/user.php create <账号> <密码> [真实姓名] [角色代码]
  php backend/bin/user.php passwd <账号> <新密码>
  php backend/bin/user.php disable <账号>
  php backend/bin/user.php enable <账号>
  php backend/bin/user.php roles
  php backend/bin/user.php role <账号> <角色代码> [角色代码...]

角色代码：editor（栏目编辑）／reviewer（审核）／publisher（发布）／admin（管理员）
create 不写角色代码时默认给 admin，保证新账号建好就能进后台。
TXT;

/**
 * 覆盖式设置账号角色；角色代码不存在时返回错误说明。
 *
 * @param list<string> $codes
 * @return string 空串表示成功，否则为错误信息
 */
$setRoles = static function (Db $db, string $username, array $codes) : string {
    $userId = $db->scalar('SELECT user_id FROM sys_user WHERE username = :u', ['u' => $username]);
    if ($userId === null) {
        return '没找到账号：' . $username;
    }
    $roleIds = [];
    foreach ($codes as $code) {
        $roleId = $db->scalar('SELECT role_id FROM sys_role WHERE code = :c', ['c' => $code]);
        if ($roleId === null) {
            return '没找到角色：' . $code;
        }
        $roleIds[] = (int) $roleId;
    }
    $db->execute('DELETE FROM sys_user_role WHERE user_id = :id', ['id' => (int) $userId]);
    foreach ($roleIds as $roleId) {
        $db->execute(
            'INSERT INTO sys_user_role (user_id, role_id) VALUES (:id, :role)',
            ['id' => (int) $userId, 'role' => $roleId]
        );
    }
    return '';
};

switch ($command) {
    case 'list':
        $rows = $db->select(
            'SELECT u.user_id, u.username, u.real_name, u.dept, u.status, u.last_login_at,
                    COALESCE(GROUP_CONCAT(r.code), \'\') AS roles
             FROM sys_user u
             LEFT JOIN sys_user_role ur ON ur.user_id = u.user_id
             LEFT JOIN sys_role r ON r.role_id = ur.role_id
             GROUP BY u.user_id, u.username, u.real_name, u.dept, u.status, u.last_login_at
             ORDER BY u.user_id'
        );
        if ($rows === []) {
            fwrite(STDOUT, "还没有后台账号，用 create 命令建一个。\n");
            break;
        }
        fwrite(STDOUT, sprintf("%-4s %-14s %-12s %-10s %-20s %-18s %s\n", 'ID', '账号', '姓名', '部门', '角色', '状态', '最后登录'));
        foreach ($rows as $row) {
            fwrite(STDOUT, sprintf(
                "%-4d %-14s %-12s %-10s %-20s %-18s %s\n",
                (int) $row['user_id'],
                (string) $row['username'],
                (string) $row['real_name'],
                (string) $row['dept'] === '' ? '—' : (string) $row['dept'],
                (string) $row['roles'] === '' ? '（未分配）' : (string) $row['roles'],
                (string) $row['status'],
                (string) ($row['last_login_at'] ?? '—')
            ));
        }
        break;

    case 'create':
        $username = $argvCopy[1] ?? '';
        $password = $argvCopy[2] ?? '';
        $realName = $argvCopy[3] ?? '';
        $roleCode = $argvCopy[4] ?? 'admin';
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
        if ($db->scalar('SELECT role_id FROM sys_role WHERE code = :c', ['c' => $roleCode]) === null) {
            fwrite(STDERR, "没找到角色：" . $roleCode . "（用 roles 查看可选角色）。\n");
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
        $error = $setRoles($db, $username, [$roleCode]);
        if ($error !== '') {
            fwrite(STDERR, $error . "\n");
            exit(1);
        }
        fwrite(STDOUT, "已创建后台账号：" . $username . ($realName !== '' ? "（" . $realName . "）" : '') . "，角色：" . $roleCode . "\n");
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
    case 'enable':
        $username = $argvCopy[1] ?? '';
        if ($username === '') {
            fwrite(STDERR, "用法：php backend/bin/user.php " . $command . " <账号>\n");
            exit(1);
        }
        $status = $command === 'disable' ? 'disabled' : 'enabled';
        $affected = $db->execute(
            'UPDATE sys_user SET status = :s, updated_at = :t WHERE username = :u',
            ['s' => $status, 't' => date('Y-m-d H:i:s'), 'u' => $username]
        );
        fwrite(STDOUT, $affected > 0
            ? ($command === 'disable' ? '已停用：' : '已启用：') . $username . "\n"
            : "没找到账号：" . $username . "\n");
        break;

    case 'roles':
        $rows = $db->select('SELECT code, name, description FROM sys_role ORDER BY sort_no, role_id');
        fwrite(STDOUT, sprintf("%-12s %-10s %s\n", '代码', '名称', '说明'));
        foreach ($rows as $row) {
            fwrite(STDOUT, sprintf("%-12s %-10s %s\n", (string) $row['code'], (string) $row['name'], (string) $row['description']));
        }
        break;

    case 'role':
        $username = $argvCopy[1] ?? '';
        $codes = array_values(array_filter(array_slice($argvCopy, 2), static fn (string $v): bool => $v !== ''));
        if ($username === '' || $codes === []) {
            fwrite(STDERR, "用法：php backend/bin/user.php role <账号> <角色代码> [角色代码...]\n");
            exit(1);
        }
        $error = $setRoles($db, $username, $codes);
        if ($error !== '') {
            fwrite(STDERR, $error . "\n");
            exit(1);
        }
        fwrite(STDOUT, "已设置 " . $username . " 的角色为：" . implode('、', $codes) . "\n");
        break;

    default:
        fwrite(STDOUT, $usage . "\n");
}
