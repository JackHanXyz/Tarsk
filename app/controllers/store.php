<?php
declare(strict_types=1);

/**
 * 软件商店（真实探测 + winget 静默安装）
 */
function store_page(): void
{
    render('store', [
        'title' => '软件商店',
        'active' => 'store',
        'apps' => store_probe_all(),
        'winget' => command_exists('winget'),
        'platform' => PHP_OS_FAMILY,
        'install_logs' => store_install_logs(),
    ], 'app');
}

function store_probe_all(bool $fresh = false): array
{
    if (!$fresh) {
        $cached = cache_get('store_catalog', 120);
        if ($cached !== null) {
            return $cached;
        }
    }

    $rows = [];
    $online = [];
    foreach (soft_catalog() as $item) {
        $probe = ($item['probe'])();
        $row = [
            'key' => $item['key'],
            'name' => $item['name'],
            'category' => $item['category'],
            'desc' => $item['desc'],
            'winget' => $item['winget'],
            'url' => $item['url'],
            'installed' => (bool)$probe['installed'],
            'version' => (string)$probe['version'],
            'path' => (string)$probe['path'],
        ];
        $rows[] = $row;

        execute(
            'INSERT INTO ' . t('soft') . ' (app_key, name, version, path, status, checked_at) VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), version = VALUES(version), path = VALUES(path),
             status = VALUES(status), checked_at = VALUES(checked_at)',
            [
                $row['key'], $row['name'], mb_substr($row['version'], 0, 60),
                mb_substr($row['path'], 0, 255), $row['installed'] ? 'installed' : 'missing', now(),
            ]
        );
    }
    unset($online);

    cache_set('store_catalog', $rows);
    return $rows;
}

function store_install(): void
{
    $key = (string)($_POST['key'] ?? '');
    $app = null;
    foreach (soft_catalog() as $item) {
        if ($item['key'] === $key) {
            $app = $item;
            break;
        }
    }
    if (!$app) {
        flash('error', '未知的软件包');
        redirect('/store');
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        flash('error', '本机安装功能当前仅支持 Windows（winget）。Linux 请使用系统包管理器安装。');
        redirect('/store');
    }
    if (command_exists('winget') === null) {
        flash('error', '未检测到 winget，请先在应用商店更新"应用安装程序"，或参考 ' . $app['url']);
        redirect('/store');
    }
    if ($app['winget'] === '') {
        flash('warning', $app['name'] . ' 未提供 winget 安装源，请前往官网下载：' . $app['url']);
        redirect('/store');
    }

    $log = storage_path('logs/install_' . $key . '.log');
    $errLog = $log . '.err';
    @unlink($log);
    @unlink($errLog);

    $script = sprintf(
        "\$ErrorActionPreference='SilentlyContinue'\nStart-Process -FilePath 'winget' -ArgumentList @('install','--id','%s','--silent','--accept-package-agreements','--accept-source-agreements','--disable-interactivity','--force') -RedirectStandardOutput '%s' -RedirectStandardError '%s' -WindowStyle Hidden",
        $app['winget'],
        str_replace("'", "''", $log),
        str_replace("'", "''", $errLog)
    );
    ps_run($script, 20);
    cache_forget('store_catalog');

    log_action('安装软件', $app['name'] . '（' . $app['winget'] . '）');
    flash('success', '已后台启动 ' . $app['name'] . ' 安装任务，1-3 分钟后点击"重新检测"查看结果');
    redirect('/store');
}

function store_check(): void
{
    $key = (string)($_POST['key'] ?? '');
    if ($key === 'all') {
        cache_forget('store_catalog');
        store_probe_all(true);
        flash('success', '已完成全部软件重新检测');
        redirect('/store');
    }
    $probe = soft_check($key);
    if ($probe === null) {
        flash('error', '未知的软件包');
        redirect('/store');
    }
    cache_forget('store_catalog');
    flash($probe['installed'] ? 'success' : 'warning', $probe['installed']
        ? ('已检测到安装：' . $probe['version'])
        : '尚未检测到安装完成，请稍后再试');
    redirect('/store');
}

function store_install_logs(): array
{
    $logs = [];
    foreach (glob(storage_path('logs/install_*.log')) ?: [] as $file) {
        $key = preg_replace('/^install_|\..*$/', '', basename($file));
        $tail = '';
        $size = filesize($file);
        if ($size > 0) {
            $handle = fopen($file, 'rb');
            fseek($handle, max(0, $size - 4096));
            $tail = (string)fread($handle, 4096);
            fclose($handle);
        }
        $logs[] = ['key' => $key, 'name' => basename($file), 'tail' => trim($tail), 'mtime' => date('m-d H:i', (int)filemtime($file))];
    }
    return $logs;
}
