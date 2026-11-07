<?php
declare(strict_types=1);

/**
 * 网站管理（目录、Nginx 托管、伪静态、建站建库）
 */

function sites_page(): void
{
    $sites = fetch_all('SELECT * FROM ' . t('sites') . ' ORDER BY id DESC');
    foreach ($sites as &$site) {
        $site['exists'] = is_dir((string)$site['path']);
        $site['size'] = $site['exists'] ? dir_size((string)$site['path']) : 0;
        $site['conf'] = site_conf_path((string)$site['name']);
        $site['effective_port'] = site_effective_port($site);
    }
    unset($site);

    render('sites', [
        'title' => '网站',
        'active' => 'sites',
        'sites' => $sites,
        'sites_root' => sites_root(),
        'nginx_bin' => find_nginx_bin(),
        'nginx_conf' => nginx_vhost_dir(),
        'nginx_managed' => managed_nginx_installed(),
        'nginx_running' => managed_nginx_installed() && runtime_state('nginx')['running'],
        'web_port' => active_web_port(),
        'php_versions' => php_versions(),
        'php_options' => site_php_options(),
        'php_managed' => managed_php_installed() ? runtime_state('php') : null,
        'rewrite_presets' => site_rewrite_presets(),
    ], 'app');
}

function sites_show(int $id): void
{
    $site = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$id]);
    if (!$site) {
        flash('error', '站点不存在');
        redirect('/sites');
    }

    $conf = site_conf_path((string)$site['name']);
    $vhost = is_file($conf) ? (string)@file_get_contents($conf) : '';
    $logPath = site_log_path($site);
    $logTail = '';
    if ($logPath !== '' && is_file($logPath)) {
        $size = (int)filesize($logPath);
        $handle = fopen($logPath, 'rb');
        if ($handle) {
            fseek($handle, max(0, $size - 60000));
            $logTail = trim((string)fread($handle, 60000));
            fclose($handle);
        }
        $rows = preg_split('/\r?\n/', $logTail);
        $logTail = implode("\n", array_slice($rows, -30));
    }

    $certs = fetch_all('SELECT * FROM ' . t('certs') . ' WHERE site_id = ? ORDER BY id DESC', [$id]);
    $databases = fetch_all('SELECT * FROM ' . t('databases') . ' WHERE site_id = ? ORDER BY id DESC', [$id]);

    render('site_detail', [
        'title' => '站点 · ' . $site['name'],
        'active' => 'sites',
        'site' => $site,
        'vhost' => $vhost,
        'conf_path' => $conf,
        'log_path' => $logPath,
        'log_tail' => $logTail,
        'certs' => $certs,
        'databases' => $databases,
        'effective_port' => site_effective_port($site),
        'rewrite_presets' => site_rewrite_presets(),
        'php_versions' => php_versions(),
        'php_options' => site_php_options(),
        'nginx_managed' => managed_nginx_installed(),
        'nginx_running' => managed_nginx_installed() && runtime_state('nginx')['running'],
    ], 'app');
}

/**
 * 校验站点 PHP 版本：未安装回退默认；已安装未运行则自动启动
 */
function sites_resolve_php(string $php, array &$notes): string
{
    if ($php === '') {
        return '';
    }
    $versions = runtime_php_versions();
    if (!isset($versions[$php])) {
        $notes[] = '未知的 PHP 版本，已使用默认版本';
        return '';
    }
    $key = $versions[$php]['key'];
    $state = runtime_state($key);
    if (!$state['installed']) {
        $notes[] = 'PHP ' . $php . ' 尚未安装（可在软件商店安装），已回退默认版本';
        return '';
    }
    if (!$state['running']) {
        $start = runtime_start($key);
        $notes[] = $start['message'];
        if (!$start['ok']) {
            return '';
        }
    }
    return $php;
}

function sites_create(): void
{
    $name = trim((string)($_POST['name'] ?? ''));
    $domain = trim((string)($_POST['domain'] ?? ''));
    $php = trim((string)($_POST['php_version'] ?? ''));
    $rewrite = (string)($_POST['rewrite'] ?? '');
    $remark = trim((string)($_POST['remark'] ?? ''));
    $createDb = !empty($_POST['create_db']);
    $dbName = trim((string)($_POST['db_name'] ?? ''));

    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,59}$/', $name)) {
        flash('error', '站点名称仅支持字母、数字、点、下划线与短横线');
        redirect('/sites');
    }
    if (fetch_one('SELECT id FROM ' . t('sites') . ' WHERE name = ?', [$name])) {
        flash('error', '站点名称已存在');
        redirect('/sites');
    }
    if (!array_key_exists($rewrite, site_rewrite_presets())) {
        $rewrite = '';
    }

    $root = sites_root();
    if (!is_dir($root)) {
        @mkdir($root, 0777, true);
    }
    $path = $root . '/' . $name;
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
    if (!is_file($path . '/index.html')) {
        @file_put_contents($path . '/index.html', site_welcome_html($name));
    }

    $port = active_web_port();
    $notes = [];
    $php = sites_resolve_php($php, $notes);
    execute(
        'INSERT INTO ' . t('sites') . '
         (name, domain, path, php_version, port, rewrite, ssl_enabled, status, remark, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$name, $domain, $path, $php, $port, $rewrite, 0, 'running', $remark, now()]
    );
    $site = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE name = ?', [$name]);

    $confResult = write_site_vhost($site);
    $notes[] = $confResult['message'];

    if ($createDb) {
        $dbName = $dbName !== '' ? $dbName : $name;
        $dbName = preg_replace('/[^A-Za-z0-9_]/', '_', $dbName);
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbName)) {
            $notes[] = '数据库名称不合法，已跳过建库';
        } else {
            $result = mysql_create_database($dbName);
            if ($result['ok']) {
                execute(
                    'INSERT INTO ' . t('databases') . ' (db_name, db_user, db_pass, site_id, remark, created_at) VALUES (?,?,?,?,?,?)',
                    [$dbName, '', '', (int)$site['id'], '随站点创建', now()]
                );
                $notes[] = $result['message'];
            } else {
                $notes[] = '建库失败：' . $result['message'];
            }
        }
    }

    log_action('创建站点', $name . ' · ' . ($domain ?: '未绑定域名'));
    flash($confResult['ok'] ? 'success' : 'warning', implode('；', $notes));
    redirect('/sites/' . (int)$site['id']);
}

function sites_update(int $id): void
{
    $site = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$id]);
    if (!$site) {
        flash('error', '站点不存在');
        redirect('/sites');
    }

    $domain = trim((string)($_POST['domain'] ?? ''));
    $php = trim((string)($_POST['php_version'] ?? ''));
    $rewrite = (string)($_POST['rewrite'] ?? '');
    $remark = trim((string)($_POST['remark'] ?? ''));
    $status = (string)($_POST['status'] ?? $site['status']);
    if (!array_key_exists($rewrite, site_rewrite_presets())) {
        $rewrite = '';
    }
    if (!in_array($status, ['running', 'stopped'], true)) {
        $status = 'running';
    }
    $notes = [];
    $php = sites_resolve_php($php, $notes);

    execute(
        'UPDATE ' . t('sites') . ' SET domain = ?, php_version = ?, rewrite = ?, remark = ?, status = ? WHERE id = ?',
        [$domain, $php, $rewrite, $remark, $status, $id]
    );
    $site = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$id]);

    $result = $status === 'running' ? write_site_vhost($site) : remove_site_vhost($site);
    $notes[] = $result['message'];
    log_action('更新站点', (string)$site['name'], 'info');
    flash($result['ok'] ? 'success' : 'warning', '站点配置已更新；' . implode('；', $notes));
    redirect('/sites/' . $id);
}

function sites_toggle(int $id): void
{
    $site = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$id]);
    if (!$site) {
        flash('error', '站点不存在');
        redirect('/sites');
    }
    $next = $site['status'] === 'running' ? 'stopped' : 'running';
    execute('UPDATE ' . t('sites') . ' SET status = ? WHERE id = ?', [$next, $id]);

    $result = $next === 'running' ? write_site_vhost($site) : remove_site_vhost($site);

    log_action('切换站点状态', $site['name'] . ' -> ' . ($next === 'running' ? '运行' : '停止'), 'warning');
    flash($result['ok'] ? 'success' : 'warning', $result['message']);
    redirect('/sites');
}

function sites_delete(int $id): void
{
    $site = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$id]);
    if (!$site) {
        flash('error', '站点不存在');
        redirect('/sites');
    }

    remove_site_vhost($site);

    if (!empty($_POST['delete_files'])) {
        $root = realpath(sites_root()) ?: sites_root();
        $target = realpath((string)$site['path']);
        if ($target && str_starts_with(str_replace('\\', '/', $target), str_replace('\\', '/', $root)) && is_dir($target)) {
            delete_path($target);
        }
    }

    execute('DELETE FROM ' . t('sites') . ' WHERE id = ?', [$id]);
    log_action('删除站点', (string)$site['name'], 'danger');
    flash('success', '站点 ' . $site['name'] . ' 已删除');
    redirect('/sites');
}
