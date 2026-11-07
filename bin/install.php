<?php
declare(strict_types=1);

/**
 * 命令行初始化：php bin/install.php [用户名] [密码]
 */
$root = dirname(__DIR__);

if (!is_file($root . '/config.php')) {
    fwrite(STDERR, "缺少 config.php，请先复制 config.example.php 并填写数据库信息。\n");
    exit(1);
}

$GLOBALS['config'] = require $root . '/config.php';
require $root . '/app/bootstrap.php';

try {
    install_schema();
    $username = $argv[1] ?? 'admin';
    $password = $argv[2] ?? 'Tarsk@2026';
    seed_data(['username' => $username, 'password' => $password]);
    file_put_contents($root . '/install.lock', date('Y-m-d H:i:s'));
    echo "Tarsk 初始化完成\n";
    echo "数据库：{$GLOBALS['config']['database']['name']}（前缀 " . db_prefix() . "）\n";
    echo "管理员：{$username}\n密码：{$password}\n";
} catch (Throwable $e) {
    fwrite(STDERR, '初始化失败：' . $e->getMessage() . "\n");
    exit(1);
}
