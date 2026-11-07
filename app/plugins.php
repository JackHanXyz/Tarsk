<?php
declare(strict_types=1);

/**
 * 插件体系：扫描 plugins 目录、加载已启用插件的路由与导航项
 *
 * 插件结构：
 *   plugins/<key>/plugin.json   {"key","name","version","description","icon","nav":{"title","href","icon"}}
 *   plugins/<key>/routes.php    返回 [ [method, pattern, 控制器文件(相对插件目录), 函数], ... ]
 */

function plugins_dir(): string
{
    $dir = __DIR__ . '/plugins';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function plugin_scan(): array
{
    $list = [];
    $base = plugins_dir();
    foreach (scandir($base) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $dir = $base . '/' . $entry;
        if (!is_dir($dir)) {
            continue;
        }
        $metaFile = $dir . '/plugin.json';
        if (!is_file($metaFile)) {
            continue;
        }
        $meta = json_decode((string)file_get_contents($metaFile), true);
        if (!is_array($meta) || empty($meta['key'])) {
            continue;
        }
        $row = null;
        try {
            $row = fetch_one('SELECT * FROM ' . t('plugins') . ' WHERE app_key = ?', [$meta['key']]);
        } catch (Throwable $e) {
            // 表未就绪
        }
        $meta['dir'] = str_replace('\\', '/', $dir);
        $meta['installed'] = $row !== null;
        $meta['enabled'] = $row ? (int)$row['enabled'] === 1 : false;
        $list[$meta['key']] = $meta;
    }
    return $list;
}

function plugin_enabled_keys(): array
{
    static $keys = null;
    if ($keys === null) {
        try {
            $keys = array_column(fetch_all('SELECT app_key FROM ' . t('plugins') . ' WHERE enabled = 1'), 'app_key');
        } catch (Throwable $e) {
            $keys = [];
        }
    }
    return $keys;
}

function plugin_routes(): array
{
    $routes = [];
    foreach (plugin_enabled_keys() as $key) {
        $file = plugins_dir() . '/' . $key . '/routes.php';
        if (!is_file($file)) {
            continue;
        }
        $defs = require $file;
        if (!is_array($defs)) {
            continue;
        }
        foreach ($defs as $def) {
            if (!is_array($def) || count($def) < 4) {
                continue;
            }
            $controller = plugins_dir() . '/' . $key . '/' . ltrim((string)$def[2], '/');
            $routes[] = [$def[0], $def[1], 'plugin:' . $controller, $def[3]];
        }
    }
    return $routes;
}

/**
 * 执行所有已启用插件的启动钩子（boot.php 中 <key>_boot()，key 内的 - 视为 _）
 */
function plugin_boot_all(): void
{
    foreach (plugin_enabled_keys() as $key) {
        $bootFile = plugins_dir() . '/' . $key . '/boot.php';
        if (!is_file($bootFile)) {
            continue;
        }
        require_once $bootFile;
        $fn = str_replace('-', '_', $key) . '_boot';
        if (function_exists($fn)) {
            $fn();
        }
    }
}

function plugin_nav_items(): array
{
    $items = [];
    foreach (plugin_enabled_keys() as $key) {
        $metaFile = plugins_dir() . '/' . $key . '/plugin.json';
        if (!is_file($metaFile)) {
            continue;
        }
        $meta = json_decode((string)file_get_contents($metaFile), true);
        if (!empty($meta['nav']) && is_array($meta['nav']) && !empty($meta['nav']['href'])) {
            $items[] = [
                'title' => (string)($meta['nav']['title'] ?? $key),
                'href' => (string)$meta['nav']['href'],
                'icon' => (string)($meta['nav']['icon'] ?? 'box'),
            ];
        }
    }
    return $items;
}
