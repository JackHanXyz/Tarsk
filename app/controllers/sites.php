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
        'extra_conf' => site_extra_conf($site),
        'extra_path' => site_extra_conf_path($site),
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
    $proxyEnabled = !empty($_POST['proxy_enabled']) ? 1 : 0;
    $proxyPass = trim((string)($_POST['proxy_pass'] ?? ''));
    $domains = trim((string)($_POST['domains'] ?? ''));
    $indexFiles = trim((string)($_POST['index_files'] ?? ''));
    $forceHttps = !empty($_POST['force_https']) ? 1 : 0;
    $antiLeech = !empty($_POST['anti_leech']) ? 1 : 0;
    $openBasedir = !empty($_POST['open_basedir']) ? 1 : 0;
    $uploadSize = strtolower(trim((string)($_POST['upload_size'] ?? '')));
    $rateLimit = min(10000, max(0, (int)($_POST['rate_limit'] ?? 0)));
    $rateBurst = min(100000, max(0, (int)($_POST['rate_burst'] ?? 0)));
    $connLimit = min(10000, max(0, (int)($_POST['conn_limit'] ?? 0)));
    $http2 = !empty($_POST['http2_enabled']) ? 1 : 0;
    $hsts = !empty($_POST['hsts_enabled']) ? 1 : 0;
    if ($uploadSize !== '' && !preg_match('/^[1-9][0-9]*[kmg]?$/', $uploadSize)) {
        $uploadSize = '64m';
    }
    if (!array_key_exists($rewrite, site_rewrite_presets())) {
        $rewrite = '';
    }
    if (!in_array($status, ['running', 'stopped'], true)) {
        $status = 'running';
    }
    if ($proxyEnabled && site_proxy_target(['proxy_enabled' => 1, 'proxy_pass' => $proxyPass]) === '') {
        flash('error', '反向代理地址格式不合法，应形如 http://127.0.0.1:3000');
        redirect('/sites/' . $id);
    }
    $notes = [];
    $php = sites_resolve_php($php, $notes);

    execute(
        'UPDATE ' . t('sites') . ' SET domain = ?, domains = ?, php_version = ?, rewrite = ?, remark = ?, status = ?, proxy_enabled = ?, proxy_pass = ?, index_files = ?, force_https = ?, anti_leech = ?, open_basedir = ?, upload_size = ?, rate_limit = ?, rate_burst = ?, conn_limit = ?, http2_enabled = ?, hsts_enabled = ? WHERE id = ?',
        [$domain, $domains, $php, $rewrite, $remark, $status, $proxyEnabled, $proxyPass, $indexFiles, $forceHttps, $antiLeech, $openBasedir, $uploadSize, $rateLimit, $rateBurst, $connLimit, $http2, $hsts, $id]
    );
    $site = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$id]);

    $result = $status === 'running' ? write_site_vhost($site) : remove_site_vhost($site);
    $notes[] = $result['message'];
    log_action('更新站点', (string)$site['name'], 'info');
    flash($result['ok'] ? 'success' : 'warning', '站点配置已更新；' . implode('；', $notes));
    redirect('/sites/' . $id);
}

/**
 * 保存站点自定义 Nginx 配置片段（带语法检测与回滚）
 */
function sites_proxy_apply(int $id): void
{
    $site = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$id]);
    if (!$site) {
        flash('error', '站点不存在');
        redirect('/sites');
    }
    if ($site['status'] !== 'running') {
        flash('error', '请先启动站点，再应用反向代理配置');
        redirect('/sites/' . $id);
    }
    $managed = PHP_OS_FAMILY === 'Windows' && managed_nginx_installed() && runtime_state('nginx')['running'];
    $linuxSystem = PHP_OS_FAMILY === 'Linux' && find_nginx_bin() !== '' && service_running('nginx') && nginx_vhost_dir() !== '';
    if (!$managed && !$linuxSystem) {
        flash('error', '未检测到可运行且已接管的 Nginx。请启动托管 Nginx，或确保 Linux 系统 Nginx 正在运行并已配置站点 include 目录');
        redirect('/sites/' . $id);
    }

    $disable = (string)($_POST['action'] ?? 'enable') === 'disable';
    $target = trim((string)($_POST['proxy_pass'] ?? ''));
    if (!$disable && site_proxy_target(['proxy_enabled' => 1, 'proxy_pass' => $target]) === '') {
        flash('error', '反向代理地址格式不合法，应形如 http://127.0.0.1:3000');
        redirect('/sites/' . $id);
    }

    $conf = site_conf_path((string)$site['name']);
    $hadConf = is_file($conf);
    $previousConf = $hadConf ? (string)@file_get_contents($conf) : '';
    $newEnabled = $disable ? 0 : 1;
    $newTarget = $disable ? (string)($site['proxy_pass'] ?? '') : $target;
    execute('UPDATE ' . t('sites') . ' SET proxy_enabled = ?, proxy_pass = ? WHERE id = ?', [$newEnabled, $newTarget, $id]);
    $candidate = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$id]);
    $written = write_site_vhost($candidate, false);
    if (!$written['ok'] || !runtime_nginx_reload()) {
        execute('UPDATE ' . t('sites') . ' SET proxy_enabled = ?, proxy_pass = ? WHERE id = ?', [(int)($site['proxy_enabled'] ?? 0), (string)($site['proxy_pass'] ?? ''), $id]);
        if ($hadConf) { @file_put_contents($conf, $previousConf, LOCK_EX); } else { @unlink($conf); }
        runtime_nginx_reload();
        flash('error', '反向代理未应用，已回滚：' . ($written['ok'] ? 'Nginx reload 失败' : $written['message']));
        redirect('/sites/' . $id);
    }

    log_action($disable ? '关闭反向代理' : '配置反向代理', (string)$site['name'] . ($disable ? '' : ' → ' . $target));
    flash('success', $disable ? '反向代理已关闭并重载 Nginx' : '反向代理已配置并生效：' . $target);
    redirect('/sites/' . $id);
}

function sites_extra_save(int $id): void
{
    $site = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$id]);
    if (!$site) {
        flash('error', '站点不存在');
        redirect('/sites');
    }
    $content = (string)($_POST['extra'] ?? '');
    $result = site_extra_apply($site, $content);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
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
    $candidate = $site;
    $candidate['status'] = $next;
    $result = $next === 'running' ? write_site_vhost($candidate) : remove_site_vhost($candidate);
    if ($result['ok']) {
        execute('UPDATE ' . t('sites') . ' SET status = ? WHERE id = ?', [$next, $id]);
    }

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

    $targetNorm = '';
    if (!empty($_POST['delete_files'])) {
        $root = realpath(sites_root());
        $target = realpath((string)$site['path']);
        $rootNorm = $root === false ? '' : rtrim(str_replace('\\', '/', $root), '/') . '/';
        $targetNorm = $target === false ? '' : str_replace('\\', '/', $target);
        $insideRoot = $rootNorm !== '' && strncasecmp($targetNorm, $rootNorm, strlen($rootNorm)) === 0;
        if (!$insideRoot || !is_dir($targetNorm)) {
            flash('error', '站点目录不在配置的站点根目录内，已拒绝删除文件');
            redirect('/sites');
        }
    }

    $remove = remove_site_vhost($site);
    if (!$remove['ok']) {
        flash('error', '未删除站点记录：' . $remove['message']);
        redirect('/sites');
    }

    if (!empty($_POST['delete_files'])) {
        if (!delete_path($targetNorm)) {
            flash('error', '站点配置已移除，但站点文件删除失败；站点记录保留');
            redirect('/sites');
        }
    }

    execute('DELETE FROM ' . t('sites') . ' WHERE id = ?', [$id]);
    log_action('删除站点', (string)$site['name'], 'danger');
    flash('success', '站点 ' . $site['name'] . ' 已删除');
    redirect('/sites');
}


/* ----------------------------- Session JSON API ----------------------------- */

function sites_api_index(): void
{
    $rows = fetch_all('SELECT * FROM ' . t('sites') . ' ORDER BY id DESC');
    $items = [];
    foreach ($rows as $site) {
        $exists = is_dir((string)$site['path']);
        $items[] = [
            'id' => (int)$site['id'],
            'name' => (string)$site['name'],
            'domain' => (string)$site['domain'],
            'domains' => (string)($site['domains'] ?? ''),
            'path' => (string)$site['path'],
            'php_version' => (string)$site['php_version'],
            'port' => site_effective_port($site),
            'ssl_enabled' => (bool)$site['ssl_enabled'],
            'status' => (string)$site['status'],
            'exists' => $exists,
            'size_bytes' => $exists ? dir_size((string)$site['path']) : 0,
            'proxy_enabled' => (bool)($site['proxy_enabled'] ?? false),
            'created_at' => (string)$site['created_at'],
        ];
    }
    header('Cache-Control: no-store');
    json_response(['ok' => true, 'items' => $items, 'count' => count($items), 'csrf_token' => csrf_token()]);
}

function sites_api_create(): void
{
    $name = trim((string)($_POST['name'] ?? ''));
    $domain = trim((string)($_POST['domain'] ?? ''));
    $php = trim((string)($_POST['php_version'] ?? ''));
    $rewrite = (string)($_POST['rewrite'] ?? '');
    $remark = trim((string)($_POST['remark'] ?? ''));

    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,59}$/', $name)) {
        json_response(['ok' => false, 'message' => '站点名称格式不合法'], 422);
    }
    if ($domain !== '' && !sites_api_valid_domain($domain)) {
        json_response(['ok' => false, 'message' => '域名格式不合法'], 422);
    }
    if (fetch_one('SELECT id FROM ' . t('sites') . ' WHERE name = ?', [$name])) {
        json_response(['ok' => false, 'message' => '站点名称已存在'], 409);
    }
    if (!array_key_exists($rewrite, site_rewrite_presets())) {
        json_response(['ok' => false, 'message' => '伪静态规则无效'], 422);
    }

    $root = sites_root();
    if (!is_dir($root) && !@mkdir($root, 0777, true) && !is_dir($root)) {
        json_response(['ok' => false, 'message' => '无法创建站点根目录'], 500);
    }
    $path = rtrim(str_replace('\\', '/', $root), '/') . '/' . $name;
    if (file_exists($path) && !is_dir($path)) {
        json_response(['ok' => false, 'message' => '目标路径已存在且不是目录'], 409);
    }
    if (!is_dir($path) && !@mkdir($path, 0777, true) && !is_dir($path)) {
        json_response(['ok' => false, 'message' => '无法创建站点目录'], 500);
    }
    $rootReal = realpath($root);
    $pathReal = realpath($path);
    $rootPrefix = $rootReal === false ? '' : rtrim(str_replace('\\', '/', $rootReal), '/') . '/';
    $pathRealNorm = $pathReal === false ? '' : rtrim(str_replace('\\', '/', $pathReal), '/') . '/';
    if ($rootPrefix === '' || $pathRealNorm === '' || strncasecmp($pathRealNorm, $rootPrefix, strlen($rootPrefix)) !== 0) {
        json_response(['ok' => false, 'message' => '站点目录解析后不在站点根目录内，已拒绝写入'], 403);
    }
    if (!is_file($path . '/index.html') && @file_put_contents($path . '/index.html', site_welcome_html($name), LOCK_EX) === false) {
        json_response(['ok' => false, 'message' => '无法写入默认首页'], 500);
    }

    $notes = [];
    $php = sites_resolve_php($php, $notes);
    $id = 0;
    try {
        execute(
            'INSERT INTO ' . t('sites') . ' (name, domain, path, php_version, port, rewrite, ssl_enabled, status, remark, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$name, $domain, $path, $php, active_web_port(), $rewrite, 0, 'running', $remark, now()]
        );
        $site = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE name = ?', [$name]);
        if (!$site) {
            throw new RuntimeException('站点记录写入后无法读取');
        }
        $id = (int)$site['id'];
        $conf = write_site_vhost($site);
        if (!$conf['ok']) {
            execute('UPDATE ' . t('sites') . " SET status = 'stopped' WHERE id = ?", [$id]);
            $site['status'] = 'stopped';
            log_action('创建站点配置失败', $name . ' · ' . $conf['message'], 'warning');
            json_response(['ok' => false, 'message' => '站点已创建但未启用：' . $conf['message'], 'item' => sites_api_item($site)], 502);
        }
        log_action('创建站点', $name . ' · ' . ($domain ?: '未绑定域名'));
        json_response(['ok' => true, 'message' => '站点已创建', 'item' => sites_api_item($site), 'warnings' => $notes], 201);
    } catch (Throwable $e) {
        if ($id > 0) {
            execute('DELETE FROM ' . t('sites') . ' WHERE id = ?', [$id]);
        }
        error_log('Tarsk sites_api_create: ' . $e->getMessage());
        json_response(['ok' => false, 'message' => '创建站点失败，请检查面板日志与数据库连接'], 500);
    }
}

function sites_api_toggle(int $id): void
{
    $site = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$id]);
    if (!$site) {
        json_response(['ok' => false, 'message' => '站点不存在'], 404);
    }
    $next = $site['status'] === 'running' ? 'stopped' : 'running';
    $candidate = $site;
    $candidate['status'] = $next;
    $result = $next === 'running' ? write_site_vhost($candidate) : remove_site_vhost($candidate);
    if (!$result['ok']) {
        json_response(['ok' => false, 'message' => $result['message'], 'item' => sites_api_item($site)], 502);
    }
    execute('UPDATE ' . t('sites') . ' SET status = ? WHERE id = ?', [$next, $id]);
    $candidate = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$id]) ?: $candidate;
    log_action('切换站点状态', $site['name'] . ' -> ' . ($next === 'running' ? '运行' : '停止'), 'warning');
    json_response(['ok' => true, 'message' => $result['message'], 'item' => sites_api_item($candidate)]);
}

function sites_api_delete(int $id): void
{
    $site = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$id]);
    if (!$site) {
        json_response(['ok' => false, 'message' => '站点不存在'], 404);
    }
    $filesDeleted = false;
    $targetNorm = '';
    if (!empty($_POST['delete_files'])) {
        $root = realpath(sites_root());
        $target = realpath((string)$site['path']);
        $rootNorm = $root === false ? '' : rtrim(str_replace('\\', '/', $root), '/') . '/';
        $targetNorm = $target === false ? '' : str_replace('\\', '/', $target);
        $insideRoot = $rootNorm !== '' && strncasecmp($targetNorm, $rootNorm, strlen($rootNorm)) === 0;
        if (!$insideRoot || !is_dir($targetNorm)) {
            json_response(['ok' => false, 'message' => '站点目录不在配置的站点根目录内，已拒绝删除文件'], 403);
        }
    }
    $remove = remove_site_vhost($site);
    if (!$remove['ok']) {
        json_response(['ok' => false, 'message' => '未删除站点记录：' . $remove['message']], 502);
    }
    if (!empty($_POST['delete_files'])) {
        if (!delete_path($targetNorm)) {
            json_response(['ok' => false, 'message' => 'Nginx 配置已移除，但站点文件删除失败；站点记录保留'], 500);
        }
        $filesDeleted = true;
    }
    execute('DELETE FROM ' . t('sites') . ' WHERE id = ?', [$id]);
    log_action('删除站点', (string)$site['name'] . ($filesDeleted ? ' · 含文件' : ''), 'danger');
    json_response(['ok' => true, 'message' => '站点已删除', 'id' => $id, 'files_deleted' => $filesDeleted]);
}

function sites_api_item(array $site): array
{
    $exists = is_dir((string)$site['path']);
    return [
        'id' => (int)$site['id'], 'name' => (string)$site['name'], 'domain' => (string)$site['domain'],
        'path' => (string)$site['path'], 'php_version' => (string)$site['php_version'],
        'port' => site_effective_port($site), 'ssl_enabled' => (bool)$site['ssl_enabled'],
        'status' => (string)$site['status'], 'exists' => $exists,
        'size_bytes' => $exists ? dir_size((string)$site['path']) : 0,
    ];
}

function sites_api_valid_domain(string $domain): bool
{
    if (strlen($domain) > 190 || preg_match('/[\x00-\x20\x7f;{}#"\']/', $domain)) {
        return false;
    }
    foreach (explode(',', $domain) as $host) {
        $host = trim($host);
        if ($host === '') {
            return false;
        }
        $host = str_starts_with($host, '*.') ? substr($host, 2) : $host;
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            continue;
        }
        if (strlen($host) > 253 || !preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/', $host)) {
            return false;
        }
    }
    return true;
}

function sites_api_update(int $id): void
{
    $site = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$id]);
    if (!$site) {
        json_response(['ok' => false, 'message' => '站点不存在'], 404);
    }
    $domain = trim((string)($_POST['domain'] ?? $site['domain']));
    $php = trim((string)($_POST['php_version'] ?? $site['php_version']));
    $rewrite = (string)($_POST['rewrite'] ?? $site['rewrite']);
    $remark = trim((string)($_POST['remark'] ?? $site['remark']));
    $status = (string)($_POST['status'] ?? $site['status']);
    if ($domain !== '' && !sites_api_valid_domain($domain)) {
        json_response(['ok' => false, 'message' => '域名格式不合法'], 422);
    }
    if (!array_key_exists($rewrite, site_rewrite_presets())) {
        json_response(['ok' => false, 'message' => '伪静态规则无效'], 422);
    }
    if (!in_array($status, ['running', 'stopped'], true)) {
        json_response(['ok' => false, 'message' => '站点状态无效'], 422);
    }
    $notes = [];
    $php = sites_resolve_php($php, $notes);
    $candidate = $site;
    $candidate['domain'] = $domain;
    $candidate['php_version'] = $php;
    $candidate['rewrite'] = $rewrite;
    $candidate['remark'] = $remark;
    $candidate['status'] = $status;
    $result = $status === 'running' ? write_site_vhost($candidate) : remove_site_vhost($candidate);
    if (!$result['ok']) {
        json_response(['ok' => false, 'message' => $result['message'], 'item' => sites_api_item($site)], 502);
    }
    execute(
        'UPDATE ' . t('sites') . ' SET domain = ?, php_version = ?, rewrite = ?, remark = ?, status = ? WHERE id = ?',
        [$domain, $php, $rewrite, $remark, $status, $id]
    );
    $updated = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$id]) ?: $candidate;
    log_action('API 更新站点', (string)$site['name']);
    json_response(['ok' => true, 'message' => '站点已更新', 'item' => sites_api_item($updated), 'warnings' => $notes]);
}
