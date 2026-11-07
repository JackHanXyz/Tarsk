<?php
declare(strict_types=1);

/**
 * 插件市场：安装、启用/禁用、卸载本地插件
 */

function plugins_page(): void
{
    require_login();
    render('plugins', [
        'title' => '插件市场',
        'active' => 'plugins',
        'plugins' => plugin_scan(),
    ], 'app');
}

function plugin_valid_key(): string
{
    return preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($_POST['key'] ?? '')));
}

function plugins_install(): void
{
    require_login();
    $key = plugin_valid_key();
    $scan = plugin_scan();
    if (!isset($scan[$key])) {
        flash('error', '插件不存在');
        redirect('/plugins');
    }
    $meta = $scan[$key];
    execute(
        'INSERT INTO ' . t('plugins') . ' (app_key, name, version, enabled, installed_at)
         VALUES (?,?,?,1,?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), version = VALUES(version), enabled = 1',
        [$key, (string)($meta['name'] ?? $key), (string)($meta['version'] ?? ''), now()]
    );
    log_action('安装插件', (string)($meta['name'] ?? $key));
    flash('success', '插件「' . ($meta['name'] ?? $key) . '」已安装并启用');
    redirect('/plugins');
}

function plugins_toggle(): void
{
    require_login();
    $key = plugin_valid_key();
    $row = fetch_one('SELECT * FROM ' . t('plugins') . ' WHERE app_key = ?', [$key]);
    if (!$row) {
        flash('error', '插件未安装');
        redirect('/plugins');
    }
    $next = (int)$row['enabled'] === 1 ? 0 : 1;
    execute('UPDATE ' . t('plugins') . ' SET enabled = ? WHERE app_key = ?', [$next, $key]);
    log_action($next === 1 ? '启用插件' : '禁用插件', $row['name']);
    flash('success', '插件已' . ($next === 1 ? '启用' : '禁用'));
    redirect('/plugins');
}

function plugins_uninstall(): void
{
    require_login();
    $key = plugin_valid_key();
    $row = fetch_one('SELECT * FROM ' . t('plugins') . ' WHERE app_key = ?', [$key]);
    if (!$row) {
        flash('error', '插件未安装');
        redirect('/plugins');
    }
    execute('DELETE FROM ' . t('plugins') . ' WHERE app_key = ?', [$key]);
    log_action('卸载插件', $row['name'], 'warning');
    flash('success', '插件已卸载（文件保留，可随时重新安装）');
    redirect('/plugins');
}
