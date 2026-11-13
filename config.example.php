<?php
/**
 * Tarsk 面板配置示例
 * 复制为 config.php 后请修改数据库与路径信息。
 */
return [
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'tarsk',       // 若账号无建库权限，安装向导会自动回落到 test 库
        'user' => 'tarsk',
        'pass' => 'tarsk',
        'charset' => 'utf8mb4',
        'prefix' => 'tarsk_',
    ],
    'app' => [
        'name' => 'Tarsk',
        'base_url' => '',
        'debug' => false,
        'version' => '1.10.0',
        'timezone' => 'Asia/Shanghai',
    ],
    'system' => [
        'sites_root' => '',       // 留空自动选择 D:/wwwroot 或 storage/www
        'backup_path' => '',      // 留空自动选择 storage/backup
        'nginx_conf' => '',       // 留空自动探测 D:/BtSoft/nginx/conf/vhost
        'mysql_bin' => '',        // 留空自动探测 MySQL 安装目录的 bin
        'nginx_bin' => '',
    ],
];
