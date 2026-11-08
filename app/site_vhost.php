<?php
declare(strict_types=1);

/**
 * 站点 Nginx 配置生成与同步
 */

function site_conf_path(string $name): string
{
    return rtrim(nginx_vhost_dir(), '/\\') . '/' . $name . '.conf';
}

/**
 * 当前 Web 服务监听端口：接管运行环境后统一走托管 Nginx 端口，避免与系统 Nginx 冲突。
 */
function active_web_port(): int
{
    if (function_exists('managed_nginx_installed') && managed_nginx_installed()) {
        return (int)setting('nginx_port', 8080);
    }
    return 80;
}

function site_effective_port(array $site): int
{
    if (function_exists('managed_nginx_installed') && managed_nginx_installed()) {
        return (int)setting('nginx_port', 8080);
    }
    return (int)($site['port'] ?? 80);
}

function active_php_port(): int
{
    return (int)setting('php_port', 9000);
}

/**
 * 站点实际使用的 PHP-CGI 端口：按站点选择的 PHP 版本解析，未安装/未运行回退默认版本
 */
function site_php_port(array $site): int
{
    $ver = trim((string)($site['php_version'] ?? ''));
    if ($ver !== '' && function_exists('runtime_php_versions')) {
        $versions = runtime_php_versions();
        if (isset($versions[$ver])) {
            $key = $versions[$ver]['key'];
            $state = runtime_state($key);
            if ($state['running']) {
                return (int)$state['port'];
            }
        }
    }
    return active_php_port();
}

/**
 * 站点可选 PHP 版本列表（含安装/运行状态），供站点表单使用
 */
function site_php_options(): array
{
    $options = [];
    foreach (runtime_php_versions() as $ver => $info) {
        $state = runtime_state($info['key']);
        $options[$ver] = [
            'label' => 'PHP ' . $ver,
            'installed' => $state['installed'],
            'running' => $state['running'],
            'port' => $state['port'],
        ];
    }
    return $options;
}

function site_rewrite_presets(): array
{
    return [
        '' => '默认（静态 / 直出）',
        'wordpress' => 'WordPress',
        'laravel' => 'Laravel',
        'thinkphp' => 'ThinkPHP',
        'discuz' => 'Discuz!',
        'dedecms' => 'DedeCMS',
    ];
}

function site_rewrite_rules(string $preset): array
{
    switch ($preset) {
        case 'wordpress':
            return ['try_files $uri $uri/ /index.php?$args;'];
        case 'laravel':
            return ['try_files $uri $uri/ /index.php?$query_string;'];
        case 'thinkphp':
            return ['if (!-e $request_filename) {', '    rewrite ^(.*)$ /index.php?s=$1 last;', '}'];
        case 'discuz':
            return [
                'rewrite ^([^\.]*)/topic-(.+)\.html$ $1/portal.php?mod=topic&topic=$2 last;',
                'rewrite ^([^\.]*)/forum-(\w+)-([0-9]+)\.html$ $1/forum.php?mod=forumdisplay&fid=$2&page=$3 last;',
                'rewrite ^([^\.]*)/thread-([0-9]+)-([0-9]+)-([0-9]+)\.html$ $1/forum.php?mod=viewthread&tid=$2&extra=page%3D$4&page=$3 last;',
                'rewrite ^([^\.]*)/view-([0-9]+)-([0-9]+)\.html$ $1/portal.php?mod=view&aid=$2&page=$3 last;',
                'rewrite ^([^\.]*)/(fid|tid)-([0-9]+)\.html$ $1/index.php?action=$2&value=$3 last;',
            ];
        case 'dedecms':
            return ['rewrite "^/plus/view.php\?aid=([0-9]+)$" /plus/view.php?aid=$1 last;'];
        default:
            return ['try_files $uri $uri/ /index.html;'];
    }
}

/**
 * 反向代理目标：仅在启用时返回规范化 URL，非法输入返回空串
 */
function site_proxy_target(array $site): string
{
    if (empty($site['proxy_enabled'])) {
        return '';
    }
    $target = trim((string)($site['proxy_pass'] ?? ''));
    if ($target === '') {
        return '';
    }
    if (!preg_match('#^https?://[A-Za-z0-9._\-]+(:\d+)?(/?[^\s;{}]*)?$#', $target)) {
        return '';
    }
    return rtrim($target, '/') ?: $target;
}

function site_vhost_content(array $site): string
{
    $serverName = ($site['domain'] ?? '') !== '' ? $site['domain'] : $site['name'] . '.local';
    $path = str_replace('\\', '/', (string)$site['path']);
    $port = site_effective_port($site);
    $phpPort = site_php_port($site);
    $proxy = site_proxy_target($site);

    $lines = [
        '# 由 Tarsk 自动生成 · ' . date('Y-m-d H:i:s'),
        'server {',
        '    listen ' . $port . ';',
        '    server_name ' . $serverName . ';',
        '    root "' . $path . '";',
        '    index index.html index.htm index.php;',
        '    client_max_body_size 64m;',
        '',
        '    location / {',
    ];
    if ($proxy !== '') {
        $lines[] = '        proxy_pass ' . $proxy . ';';
        $lines[] = '        proxy_set_header Host $host;';
        $lines[] = '        proxy_set_header X-Real-IP $remote_addr;';
        $lines[] = '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;';
        $lines[] = '        proxy_set_header X-Forwarded-Proto $scheme;';
        $lines[] = '        proxy_http_version 1.1;';
        $lines[] = '        proxy_read_timeout 300s;';
        $lines[] = '        proxy_send_timeout 300s;';
    } else {
        foreach (site_rewrite_rules((string)($site['rewrite'] ?? '')) as $rule) {
            $lines[] = '        ' . $rule;
        }
    }
    $lines[] = '    }';
    if ($proxy === '') {
        $lines[] = '';
        $lines[] = '    location ~ \\.php$ {';
        $lines[] = '        fastcgi_pass 127.0.0.1:' . $phpPort . ';';
        $lines[] = '        fastcgi_index index.php;';
        $lines[] = '        include fastcgi_params;';
        $lines[] = '        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;';
        $lines[] = '    }';
    }
    $lines[] = '';
    $lines[] = '    access_log logs/' . $site['name'] . '.log;';
    $lines[] = '}';

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
            if ($proxy !== '') {
                $lines[] = '    location / {';
                $lines[] = '        proxy_pass ' . $proxy . ';';
                $lines[] = '        proxy_set_header Host $host;';
                $lines[] = '        proxy_set_header X-Real-IP $remote_addr;';
                $lines[] = '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;';
                $lines[] = '        proxy_set_header X-Forwarded-Proto $scheme;';
                $lines[] = '    }';
                $lines[] = '    access_log logs/' . $site['name'] . '.log;';
            } else {
                $lines[] = '    location / { try_files $uri $uri/ /index.html; }';
            }
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

    $managed = function_exists('managed_nginx_installed') && managed_nginx_installed();
    $note = 'Nginx 配置已写入 ' . $conf;
    if ($managed) {
        $note .= '（由 Tarsk 托管 Nginx 加载，端口 ' . active_web_port() . '）';
        if (function_exists('runtime_nginx_reload')) {
            runtime_nginx_reload();
        }
    } elseif (function_exists('service_running') && service_running('nginx')) {
        $note .= '，执行 nginx -s reload 后生效';
    }
    return ['ok' => true, 'message' => $note];
}

function remove_site_vhost(array $site): array
{
    $conf = site_conf_path((string)$site['name']);
    if (is_file($conf)) {
        @unlink($conf);
        if (function_exists('runtime_nginx_reload')) {
            runtime_nginx_reload();
        }
        return ['ok' => true, 'message' => '已移除 Nginx 配置 ' . $conf];
    }
    return ['ok' => true, 'message' => '未发现 Nginx 配置，已跳过'];
}

function sync_all_site_vhosts(): void
{
    if (!function_exists('fetch_all')) {
        return;
    }
    foreach (fetch_all('SELECT * FROM ' . t('sites')) as $site) {
        write_site_vhost($site);
    }
}

function site_log_path(array $site): string
{
    $root = function_exists('managed_nginx_root') ? managed_nginx_root() : null;
    if ($root === null) {
        $root = function_exists('find_nginx_root') ? find_nginx_root() : '';
    }
    if ($root === '') {
        return '';
    }
    return rtrim(str_replace('\\', '/', $root), '/') . '/logs/' . $site['name'] . '.log';
}

function site_welcome_html(string $name): string
{
    return '<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8">
<title>' . e($name) . ' · Tarsk</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#041012;color:#e3efef;font-family:-apple-system,"Segoe UI","Microsoft YaHei",sans-serif}
.card{border:1px solid rgba(45,212,191,.22);background:rgba(12,23,27,.8);padding:48px 56px;border-radius:18px;text-align:center;box-shadow:0 30px 80px -30px rgba(0,0,0,.8)}
h1{margin:0 0 12px;font-size:26px;letter-spacing:.5px}
p{margin:0;color:#8aa4a8;font-size:14px;line-height:1.8}
span{color:#2dd4bf}
</style></head>
<body><div class="card"><h1>' . e($name) . '</h1><p>站点已由 <span>Tarsk</span> 创建，替换 index.html 即可上线。</p></div></body></html>';
}
