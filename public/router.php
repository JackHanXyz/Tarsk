<?php
/**
 * PHP 内置服务器路由脚本
 * 用法：php -S 127.0.0.1:8888 -t public public/router.php
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}
require __DIR__ . '/index.php';
