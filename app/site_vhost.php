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
 * 站点自定义配置片段：被 include 进该站点的每个 server 块
 */
function site_extra_conf_path(array $site): string
{
    return dirname(site_conf_path((string)$site['name'])) . '/extra/' . $site['name'] . '.conf';
}

function site_extra_conf(array $site): string
{
    $path = site_extra_conf_path($site);
    return is_file($path) ? (string)@file_get_contents($path) : '';
}

/**
 * 保存站点自定义配置：备份 → 写入 → 重建 vhost → nginx -t → 失败回滚
 */
function site_extra_apply(array $site, string $content): array
{
    $path = site_extra_conf_path($site);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $exists = is_file($path);
    $original = $exists ? (string)@file_get_contents($path) : '';

    if (trim($content) === '') {
        if (!$exists) {
            return ['ok' => true, 'message' => '自定义配置为空，无需变更'];
        }
        if (function_exists('env_backup')) {
            env_backup($path, 'site-extra');
        }
        @unlink($path);
        $result = write_site_vhost($site);
        return ['ok' => true, 'message' => '自定义配置已清空；' . $result['message']];
    }

    if (function_exists('env_backup')) {
        env_backup($path, 'site-extra');
    }
    if (@file_put_contents($path, $content) === false) {
        return ['ok' => false, 'message' => '写入失败：' . $path];
    }

    $written = write_site_vhost($site, false);
    if (!$written['ok']) {
        return $written;
    }

    $managed = function_exists('managed_nginx_installed') && managed_nginx_installed();
    if ($managed && function_exists('nginx_conf_test')) {
        $test = nginx_conf_test();
        if (!$test['ok']) {
            // 回滚：还原文件（或删除新增文件）并重建 vhost
            if ($exists) {
                @file_put_contents($path, $original);
            } else {
                @unlink($path);
            }
            write_site_vhost($site, false);
            return ['ok' => false, 'message' => '配置未通过 nginx -t，已回滚：' . $test['message']];
        }
        if (function_exists('runtime_nginx_reload')) {
            runtime_nginx_reload();
        }
    }

    if (function_exists('log_action')) {
        log_action('修改站点自定义配置', (string)$site['name'], 'warning');
    }
    return ['ok' => true, 'message' => '自定义配置已保存并生效'];
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
    if (!preg_match('#^https?://[A-Za-z0-9._-]+(?::([0-9]{1,5}))?(/[A-Za-z0-9._~!%&()+,=:@/-]*)?$#', $target, $m)) {
        return '';
    }
    if (isset($m[1]) && $m[1] !== '' && ((int)$m[1] < 1 || (int)$m[1] > 65535)) {
        return '';
    }
    return rtrim($target, '/') ?: $target;
}

/**
 * 站点绑定的全部域名（主域名 + 附加域名），去重并过滤非法项
 */
function site_server_names(array $site): string
{
    $names = [];
    $primary = trim((string)($site['domain'] ?? ''));
    if ($primary !== '') {
        $names[] = $primary;
    }
    $extra = str_replace(["\r\n", "\r", "\n", ','], ' ', (string)($site['domains'] ?? ''));
    foreach (preg_split('/\s+/', trim($extra)) as $item) {
        $item = trim($item);
        if ($item !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-]{1,190}$/', $item)) {
            $names[] = $item;
        }
    }
    $names = array_values(array_unique($names));
    return $names ? implode(' ', $names) : ($site['name'] . '.local');
}

/**
 * 站点默认文档
 */
function site_index_files(array $site): string
{
    $raw = trim((string)($site['index_files'] ?? ''));
    $files = [];
    foreach (preg_split('/\s+/', $raw) as $item) {
        $item = trim($item);
        if ($item !== '' && preg_match('/^[A-Za-z0-9._\-]+$/', $item)) {
            $files[] = $item;
        }
    }
    return $files ? implode(' ', array_slice(array_unique($files), 0, 6)) : 'index.html index.htm index.php';
}

/**
 * 站点上传大小：站点级 > 全局设置 > 64m
 */
function site_upload_limit(array $site): string
{
    $value = strtolower(trim((string)($site['upload_size'] ?? '')));
    if ($value === '') {
        // 回落到全局 Nginx 配置里的值
        $value = '64m';
        if (function_exists('nginx_conf_path')) {
            $confPath = nginx_conf_path();
            if ($confPath !== null) {
                $conf = (string)@file_get_contents($confPath);
                if (preg_match('/client_max_body_size\s+([0-9]+[kmg]?)\s*;/i', $conf, $m)) {
                    $value = strtolower($m[1]);
                }
            }
        }
    }
    if (!preg_match('/^[1-9][0-9]*[kmg]?$/', $value)) {
        return '64m';
    }
    return $value;
}

/**
 * 站点限流：limit_req + limit_conn（共享内存区由 tarsk-zones.conf 提供）
 */
function site_rate_limit_lines(array $site): array
{
    $lines = [];
    $rate = (int)($site['rate_limit'] ?? 0);
    if ($rate > 0) {
        $burst = (int)($site['rate_burst'] ?? 0);
        $zone = function_exists('site_rate_zone') ? site_rate_zone($rate) : 'tarsk_req';
        $lines[] = 'limit_req zone=' . $zone . ' burst=' . ($burst > 0 ? $burst : max(1, $rate * 2)) . ' nodelay;';
    }
    $conn = (int)($site['conn_limit'] ?? 0);
    if ($conn > 0) {
        $lines[] = 'limit_conn tarsk_conn ' . $conn . ';';
    }
    return $lines;
}

function site_vhost_content(array $site): string
{
    $serverNames = site_server_names($site);
    $path = str_replace('\\', '/', (string)$site['path']);
    $port = site_effective_port($site);
    $phpPort = site_php_port($site);
    $proxy = site_proxy_target($site);
    $index = site_index_files($site);
    $maxBody = site_upload_limit($site);
    $rateLimit = site_rate_limit_lines($site);
    $hsts = !empty($site['hsts_enabled']);
    $extraPath = str_replace('\\', '/', site_extra_conf_path($site));
    $extraInclude = is_file($extraPath)
        ? ['', '    # 站点自定义配置（站点详情页可编辑）', '    include "' . $extraPath . '";']
        : [];

    $cert = null;
    if (!empty($site['ssl_enabled'])) {
        $cert = fetch_one(
            'SELECT * FROM ' . t('certs') . ' WHERE site_id = ? ORDER BY id DESC LIMIT 1',
            [$site['id']]
        );
    }
    // 没有可用证书时不强制跳转，避免把自己 301 到不存在的 443
    $forceHttps = !empty($site['force_https']) && $cert && is_file((string)$cert['cert_path']);

    $lines = [
        '# 由 Tarsk 自动生成 · ' . date('Y-m-d H:i:s'),
        'server {',
        '    listen ' . $port . ';',
        '    server_name ' . $serverNames . ';',
        '    root "' . $path . '";',
        '    index ' . $index . ';',
        '    client_max_body_size ' . $maxBody . ';',
    ];
    foreach ($rateLimit as $rule) {
        $lines[] = '    ' . $rule;
    }
    foreach ($extraInclude as $line) {
        $lines[] = $line;
    }

    $lines[] = '';
    $lines[] = '    location ^~ /.well-known/acme-challenge/ {';
    $lines[] = '        root "' . $path . '";';
    $lines[] = '        default_type text/plain;';
    $lines[] = '        try_files $uri =404;';
    $lines[] = '    }';
    if ($forceHttps) {
        $lines[] = '';
        $lines[] = '    location / {';
        $lines[] = '        return 301 https://$host$request_uri;';
        $lines[] = '    }';
        $lines[] = '}';
    } else {
        $lines[] = '';
        $lines[] = '    location / {';
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

        if (!empty($site['anti_leech'])) {
            $lines[] = '';
            $lines[] = '    location ~* \\.(gif|jpg|jpeg|png|webp|bmp|swf|flv|mp4|mp3|zip|rar)$ {';
            $lines[] = '        valid_referers none blocked server_names;';
            $lines[] = '        if ($invalid_referer) {';
            $lines[] = '            return 403;';
            $lines[] = '        }';
            $lines[] = '        expires 30d;';
            $lines[] = '    }';
        }

        if ($proxy === '') {
            $lines[] = '';
            $lines[] = '    location ~ \\.php$ {';
            $lines[] = '        fastcgi_pass 127.0.0.1:' . $phpPort . ';';
            $lines[] = '        fastcgi_index index.php;';
            $lines[] = '        include fastcgi_params;';
            $lines[] = '        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;';
            if (!empty($site['open_basedir'])) {
                $lines[] = '        fastcgi_param PHP_ADMIN_VALUE "open_basedir=' . $path . '/;' . storage_path('tmp') . '/";';
            }
            $lines[] = '    }';
        }
        $lines[] = '';
        $lines[] = '    access_log logs/' . $site['name'] . '.log;';
        $lines[] = '}';
    }

    if ($cert && is_file((string)$cert['cert_path'])) {
        $http2 = !empty($site['http2_enabled']);
        $http2OnDirective = $http2 && function_exists('nginx_supports_http2_directive') && nginx_supports_http2_directive();
        $lines[] = '';
        $lines[] = 'server {';
        $lines[] = '    listen 443 ssl' . ($http2 && !$http2OnDirective ? ' http2' : '') . ';';
        if ($http2OnDirective) {
            $lines[] = '    http2 on;';
        }
        $lines[] = '    server_name ' . $serverNames . ';';
        $lines[] = '    root "' . $path . '";';
        $lines[] = '    index ' . $index . ';';
        $lines[] = '    client_max_body_size ' . $maxBody . ';';
        foreach ($rateLimit as $rule) {
            $lines[] = '    ' . $rule;
        }
        if ($hsts) {
            $lines[] = '    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;';
        }
        foreach ($extraInclude as $line) {
            $lines[] = $line;
        }
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
            $lines[] = '        proxy_http_version 1.1;';
            $lines[] = '        proxy_read_timeout 300s;';
            $lines[] = '        proxy_send_timeout 300s;';
            $lines[] = '    }';
        } else {
            $lines[] = '    location / {';
            foreach (site_rewrite_rules((string)($site['rewrite'] ?? '')) as $rule) {
                $lines[] = '        ' . $rule;
            }
            $lines[] = '    }';
            $lines[] = '';
            $lines[] = '    location ~ \\.php$ {';
            $lines[] = '        fastcgi_pass 127.0.0.1:' . $phpPort . ';';
            $lines[] = '        fastcgi_index index.php;';
            $lines[] = '        include fastcgi_params;';
            $lines[] = '        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;';
            if (!empty($site['open_basedir'])) {
                $lines[] = '        fastcgi_param PHP_ADMIN_VALUE "open_basedir=' . $path . '/;' . storage_path('tmp') . '/";';
            }
            $lines[] = '    }';
        }
        $lines[] = '    access_log logs/' . $site['name'] . '.log;';
        $lines[] = '}';
    }

    $lines[] = '';
    return implode("\n", $lines);
}

function write_site_vhost(array $site, bool $reload = true): array
{
    if (function_exists('env_ensure_zones')) {
        env_ensure_zones();
    }
    if (PHP_OS_FAMILY === 'Linux' && nginx_vhost_dir() === '') {
        return ['ok' => false, 'message' => '未找到系统 Nginx 已启用的 vhost include 目录；请确认 nginx -T 中包含 conf.d/*.conf/sites-enabled/*.conf，或配置 system.nginx_conf'];
    }
    $conf = site_conf_path((string)$site['name']);
    $dir = dirname($conf);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $hadConf = is_file($conf);
    $previousConf = $hadConf ? (string)@file_get_contents($conf) : '';
    $written = @file_put_contents($conf, site_vhost_content($site));
    if ($written === false) {
        return ['ok' => false, 'message' => 'Nginx 配置写入失败：' . $conf];
    }

    if (PHP_OS_FAMILY === 'Linux') {
        $test = function_exists('nginx_conf_test') ? nginx_conf_test() : ['ok' => false, 'message' => '缺少 Nginx 配置测试器'];
        if (empty($test['ok'])) {
            if ($hadConf) { @file_put_contents($conf, $previousConf, LOCK_EX); } else { @unlink($conf); }
            return ['ok' => false, 'message' => 'Linux Nginx 配置校验失败，已回滚：' . ($test['message'] ?? '未知错误')];
        }
        if ($reload && !runtime_nginx_reload()) {
            if ($hadConf) { @file_put_contents($conf, $previousConf, LOCK_EX); } else { @unlink($conf); }
            runtime_nginx_reload();
            return ['ok' => false, 'message' => 'Nginx reload 失败，已回滚站点配置；请检查服务权限与 systemd 状态'];
        }
        $note = 'Linux Nginx 配置校验通过：' . $conf;
        $note .= $reload ? '；系统 Nginx 已重载' : '；待应用配置时重载';
        return ['ok' => true, 'message' => $note];
    }

    $managed = function_exists('managed_nginx_installed') && managed_nginx_installed();
    $note = 'Nginx 配置已写入 ' . $conf;
    if ($managed) {
        $note .= '（由 Tarsk 托管 Nginx 加载，端口 ' . active_web_port() . '）';
        $nginxRunning = (bool)(runtime_state('nginx')['running'] ?? false);
        if ($reload && $nginxRunning && function_exists('runtime_nginx_reload') && !runtime_nginx_reload()) {
            if ($hadConf) { @file_put_contents($conf, $previousConf, LOCK_EX); } else { @unlink($conf); }
            runtime_nginx_reload();
            return ['ok' => false, 'message' => '托管 Nginx reload 失败，已回滚站点配置'];
        }
    } elseif (function_exists('service_running') && service_running('nginx')) {
        $note .= '，执行 nginx -s reload 后生效';
    }
    return ['ok' => true, 'message' => $note];
}

function remove_site_vhost(array $site): array
{
    $conf = site_conf_path((string)$site['name']);
    $extra = site_extra_conf_path($site);
    $hadConf = is_file($conf);
    $hadExtra = is_file($extra);
    $previousConf = $hadConf ? (string)@file_get_contents($conf) : '';
    $previousExtra = $hadExtra ? (string)@file_get_contents($extra) : '';
    if ($hadConf && !@unlink($conf)) {
        return ['ok' => false, 'message' => '无法移除 Nginx 站点配置：' . $conf];
    }
    if ($hadExtra && !@unlink($extra)) {
        if ($hadConf) { @file_put_contents($conf, $previousConf, LOCK_EX); }
        return ['ok' => false, 'message' => '无法移除站点自定义配置：' . $extra];
    }
    $managedRunning = function_exists('managed_nginx_installed')
        && managed_nginx_installed()
        && (bool)(runtime_state('nginx')['running'] ?? false);
    $nginxRunning = $managedRunning || (function_exists('service_running') && service_running('nginx'));
    if (($hadConf || $hadExtra) && $nginxRunning && function_exists('runtime_nginx_reload') && !runtime_nginx_reload()) {
        if ($hadConf) { @file_put_contents($conf, $previousConf, LOCK_EX); }
        if ($hadExtra) { @file_put_contents($extra, $previousExtra, LOCK_EX); }
        runtime_nginx_reload();
        return ['ok' => false, 'message' => 'Nginx reload 失败，已恢复站点配置；站点状态未变更'];
    }
    return ['ok' => true, 'message' => $hadConf || $hadExtra ? '已移除 Nginx 站点配置' : '未发现 Nginx 配置，已跳过'];
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
