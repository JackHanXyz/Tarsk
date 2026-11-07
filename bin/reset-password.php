<?php
declare(strict_types=1);

/**
 * 重置管理员密码：php bin/reset-password.php [用户名] [新密码]
 */
$root = dirname(__DIR__);

if (!is_file($root . '/config.php')) {
    fwrite(STDERR, "缺少 config.php\n");
    exit(1);
}

$GLOBALS['config'] = require $root . '/config.php';
require $root . '/app/bootstrap.php';

$username = $argv[1] ?? 'admin';
$password = $argv[2] ?? '';
if ($username === '' || $password === '' || strlen($password) < 8) {
    fwrite(STDERR, "用法：php bin/reset-password.php <用户名> <新密码（至少8位）>\n");
    exit(1);
}

$user = fetch_one('SELECT id FROM ' . t('users') . ' WHERE username = ?', [$username]);
if ($user) {
    execute('UPDATE ' . t('users') . ' SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    echo "已更新 {$username} 的密码。\n";
} else {
    execute(
        'INSERT INTO ' . t('users') . ' (username, password_hash, role, created_at) VALUES (?,?,?,?)',
        [$username, password_hash($password, PASSWORD_DEFAULT), 'admin', now()]
    );
    echo "已创建管理员 {$username}。\n";
}
