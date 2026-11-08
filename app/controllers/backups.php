<?php
declare(strict_types=1);

/**
 * 备份中心：站点与数据库备份的创建、下载、恢复、删除
 */

function backups_page(): void
{
    $items = backup_list();
    $siteItems = array_values(array_filter($items, fn($i) => $i['kind'] === 'site'));
    $dbItems = array_values(array_filter($items, fn($i) => $i['kind'] === 'db'));

    $sites = fetch_all('SELECT id, name, domain FROM ' . t('sites') . ' ORDER BY id DESC');
    $databases = mysql_databases();
    $totalSize = array_sum(array_map(fn($i) => $i['size'], $items));

    render('backups', [
        'title' => '备份管理',
        'active' => 'backups',
        'items' => $items,
        'site_backups' => $siteItems,
        'db_backups' => $dbItems,
        'sites' => $sites,
        'databases' => $databases,
        'backup_dir' => backup_dir(),
        'retention' => (int)setting('backup_retention', '7'),
        'total_size' => $totalSize,
        'pack_available' => backup_zip_available() || backup_tar_bin() !== '',
    ], 'app');
}

function backups_create(): void
{
    $kind = (string)($_POST['kind'] ?? '');
    if ($kind === 'site') {
        $result = backup_site_create((int)($_POST['site_id'] ?? 0));
    } elseif ($kind === 'db') {
        $result = backup_db_create((string)($_POST['db_name'] ?? ''));
    } else {
        $result = ['ok' => false, 'message' => '未知的备份类型'];
    }

    log_action('创建备份', $kind . ' · ' . (string)($_POST['db_name'] ?? $_POST['site_id'] ?? ''), $result['ok'] ? 'info' : 'warning');
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/backups');
}

function backups_delete(): void
{
    $name = (string)($_POST['name'] ?? '');
    $result = backup_delete($name);
    log_action('删除备份', $name, 'warning');
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/backups');
}

function backups_download(): void
{
    $file = backup_resolve((string)($_GET['name'] ?? ''));
    if ($file === null) {
        http_response_code(404);
        exit('备份文件不存在');
    }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($file));
    header('Content-Disposition: attachment; filename="' . rawurlencode(basename($file)) . '"');
    readfile($file);
    exit;
}

function backups_restore(): void
{
    $name = (string)($_POST['name'] ?? '');
    $kind = (string)($_POST['kind'] ?? '');
    if ($kind === 'site') {
        $result = backup_site_restore($name, (int)($_POST['site_id'] ?? 0));
    } elseif ($kind === 'db') {
        $result = backup_db_restore($name, (string)($_POST['db_name'] ?? ''));
    } else {
        $result = ['ok' => false, 'message' => '未知的恢复类型'];
    }

    log_action('恢复备份', $name, $result['ok'] ? 'warning' : 'danger');
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/backups');
}
