<?php
declare(strict_types=1);

/**
 * 网站管理
 */
function sites_page(): void
{
    $sites = fetch_all('SELECT * FROM ' . t('sites') . ' ORDER BY id DESC');
    foreach ($sites as &$site) {
        $site['exists'] = is_dir((string)$site['path']);
        $site['size'] = $site['exists'] ? dir_size((string)$site['path']) : 0;
        $site['conf'] = site_conf_path((string)$site['name']);
    }
    unset($site);

    render('sites', [
        'title' => '网站',
        'active' => 'sites',
        'sites' => $sites,
        'sites_root' => sites_root(),
        'nginx_bin' => find_nginx_bin(),
        'nginx_conf' => nginx_vhost_dir(),
        'php_versions' => php_versions(),
    ], 'app');
}

function sites_create(): void
{
    $name = trim((string)($_POST['name'] ?? ''));
    $domain = trim((string)($_POST['domain'] ?? ''));
    $php = trim((string)($_POST['php_version'] ?? ''));
    $port = (int)($_POST['port'] ?? 80);
    $remark = trim((string)($_POST['remark'] ?? ''));

    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,59}$/', $name)) {
        flash('error', '站点名称仅支持字母、数字、点、下划线与短横线');
        redirect('/sites');
    }
    if (fetch_one('SELECT id FROM ' . t('sites') . ' WHERE name = ?', [$name])) {
        flash('error', '站点名称已存在');
        redirect('/sites');
    }
    if ($port < 1 || $port > 65535) {
        $port = 80;
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

    execute(
        'INSERT INTO ' . t('sites') . ' (name, domain, path, php_version, port, ssl_enabled, status, remark, created_at)
         VALUES (?,?,?,?,?,?,?,?,?)',
        [$name, $domain, $path, $php, $port, 0, 'running', $remark, now()]
    );
    // 重新查询以取得插入后的完整记录
    $site = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE name = ?', [$name]);

    $confResult = write_site_vhost($site);
    log_action('创建站点', $name . ' · ' . ($domain ?: '未绑定域名'), 'info');

    flash($confResult['ok'] ? 'success' : 'warning', '站点目录已创建：' . $path . '；' . $confResult['message']);
    redirect('/sites');
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

    if ($next === 'running') {
        $result = write_site_vhost($site);
    } else {
        $result = remove_site_vhost($site);
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

/* ------------------------------- 内部方法 ------------------------------- */

function site_conf_path(string $name): string
{
    return nginx_vhost_dir() . '/' . $name . '.conf';
}

function site_vhost_content(array $site): string
{
    $serverName = $site['domain'] !== '' ? $site['domain'] : $site['name'] . '.local';
    $path = str_replace('\\', '/', (string)$site['path']);
    $lines = [
        '# 由 Tarsk 自动生成 · ' . date('Y-m-d H:i:s'),
        'server {',
        '    listen ' . (int)$site['port'] . ';',
        '    server_name ' . $serverName . ';',
        '    root "' . $path . '";',
        '    index index.html index.htm index.php;',
        '',
        '    location / {',
        '        try_files $uri $uri/ /index.html;',
        '    }',
        '',
        '    location ~ \\.php$ {',
        '        fastcgi_pass 127.0.0.1:9000;',
        '        fastcgi_index index.php;',
        '        include fastcgi_params;',
        '        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;',
        '    }',
        '',
        '    access_log logs/' . $site['name'] . '.log;',
        '}',
    ];

    if (!empty($site['ssl_enabled'])) {
        $cert = fetch_one(
            'SELECT * FROM ' . t('certs') . ' WHERE site_id = ? ORDER BY id DESC LIMIT 1',
            [$site['id']]
        );
        if ($cert && is_file((string)$cert['cert_path'])) {
            $lines[] = '';
            $lines[] = 'server {';
            $lines[] = '    listen 443 ssl;';
            $lines[] = '    server_name ' . $serverName . ';';
            $lines[] = '    root "' . $path . '";';
            $lines[] = '    index index.html index.htm index.php;';
            $lines[] = '    ssl_certificate "' . str_replace('\\', '/', (string)$cert['cert_path']) . '";';
            $lines[] = '    ssl_certificate_key "' . str_replace('\\', '/', (string)$cert['key_path']) . '";';
            $lines[] = '    ssl_protocols TLSv1.2 TLSv1.3;';
            $lines[] = '    location / { try_files $uri $uri/ /index.html; }';
            $lines[] = '}';
        }
    }

    $lines[] = '';
    return implode("\n", $lines);
}

function write_site_vhost(array $site): array
{
    $conf = site_conf_path((string)$site['name']);
    $dir = dirname($conf);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $written = @file_put_contents($conf, site_vhost_content($site));
    if ($written === false) {
        return ['ok' => false, 'message' => 'Nginx 配置写入失败：' . $conf];
    }
    $note = 'Nginx 配置已写入 ' . $conf;
    if (find_nginx_bin() !== '' && service_running('nginx')) {
        $note .= '，执行 nginx -s reload 后生效';
    }
    return ['ok' => true, 'message' => $note];
}

function remove_site_vhost(array $site): array
{
    $conf = site_conf_path((string)$site['name']);
    if (is_file($conf)) {
        @unlink($conf);
        return ['ok' => true, 'message' => '已移除 Nginx 配置 ' . $conf];
    }
    return ['ok' => true, 'message' => '未发现 Nginx 配置，已跳过'];
}

function site_welcome_html(string $name): string
{
    return '<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8">
<title>' . e($name) . ' · Tarsk</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0f172a;color:#e2e8f0;font-family:-apple-system,"Segoe UI","Microsoft YaHei",sans-serif}
.card{border:1px solid rgba(148,163,184,.25);background:rgba(30,41,59,.65);padding:48px 56px;border-radius:18px;text-align:center}
h1{margin:0 0 12px;font-size:26px;letter-spacing:.5px}
p{margin:0;color:#94a3b8;font-size:14px;line-height:1.8}
span{color:#38bdf8}
</style></head>
<body><div class="card"><h1>' . e($name) . '</h1><p>站点已由 <span>Tarsk</span> 创建，替换 index.html 即可上线。</p></div></body></html>';
}
