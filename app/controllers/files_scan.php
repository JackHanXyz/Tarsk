<?php
declare(strict_types=1);

/**
 * 站点文件安全：完整性基线比对（防篡改）与 Webshell 扫描
 */

function files_scan_site(): ?array
{
    $name = (string)($_GET['site'] ?? $_POST['site'] ?? '');
    if ($name !== '') {
        return fetch_one('SELECT * FROM ' . t('sites') . ' WHERE name = ?', [$name]);
    }
    $first = fetch_one('SELECT * FROM ' . t('sites') . ' ORDER BY name LIMIT 1');
    return $first ?: null;
}

function files_scan_page(): void
{
    $site = files_scan_site();
    $sites = fetch_all('SELECT id, name, domain, path FROM ' . t('sites') . ' ORDER BY name');

    render('files_scan', [
        'title' => '文件安全',
        'active' => 'files_scan',
        'sites' => $sites,
        'site' => $site,
        'baseline' => $site ? fs_baseline_meta((string)$site['name']) : null,
        'result' => $site ? fs_last_result((string)$site['name']) : null,
        'rules' => fs_malware_rules(),
    ], 'app');
}

function files_scan_baseline(): void
{
    $site = files_scan_site();
    if (!$site) {
        flash('error', '请先选择站点');
        redirect('/files/scan');
    }
    $result = fs_baseline_create((string)$site['name'], (string)$site['path']);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/files/scan?site=' . urlencode((string)$site['name']));
}

function files_scan_compare(): void
{
    $site = files_scan_site();
    if (!$site) {
        flash('error', '请先选择站点');
        redirect('/files/scan');
    }
    $result = fs_compare((string)$site['name'], (string)$site['path']);
    if (!$result['ok']) {
        flash('error', $result['message']);
    } else {
        $counts = $result['counts'];
        $message = $result['clean']
            ? '没有检测到任何文件变化'
            : '发现变化：新增 ' . $counts['added'] . ' · 修改 ' . $counts['modified'] . ' · 删除 ' . $counts['removed'];
        flash($result['clean'] ? 'success' : 'warning', $message);
        log_action('文件完整性比对', (string)$site['name'] . ' · ' . $message, $result['clean'] ? 'info' : 'warning');
    }
    redirect('/files/scan?site=' . urlencode((string)$site['name']));
}

function files_scan_run(): void
{
    $site = files_scan_site();
    if (!$site) {
        flash('error', '请先选择站点');
        redirect('/files/scan');
    }
    $result = fs_malware_scan((string)$site['name'], (string)$site['path']);
    if (!$result['ok']) {
        flash('error', $result['message']);
    } else {
        $message = $result['hits']
            ? '扫描 ' . $result['scanned'] . ' 个文件，命中 ' . count($result['hits']) . ' 处可疑代码'
            : '扫描 ' . $result['scanned'] . ' 个文件，未发现可疑代码';
        flash($result['hits'] ? 'warning' : 'success', $message);
        log_action('Webshell 扫描', (string)$site['name'] . ' · ' . $message, $result['hits'] ? 'warning' : 'info');
    }
    redirect('/files/scan?site=' . urlencode((string)$site['name']));
}

/**
 * 打开命中文件（跳到文件编辑器）
 */
function files_scan_open(): void
{
    $site = files_scan_site();
    $rel = (string)($_GET['path'] ?? '');
    if (!$site || $rel === '') {
        flash('error', '参数不完整');
        redirect('/files/scan');
    }
    redirect('/files?path=' . urlencode((string)$site['name'] . '/' . $rel));
}
