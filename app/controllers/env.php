<?php
declare(strict_types=1);

/**
 * 环境配置：PHP 参数/扩展、Nginx 全局、MySQL 运行状态、Redis 数据浏览
 */

function env_version_from_key(string $key): string
{
    foreach (runtime_php_versions() as $version => $info) {
        if ($info['key'] === $key) {
            return (string)$version;
        }
    }
    return '8.3';
}

function env_pick_php_version(string $version): string
{
    $versions = runtime_php_versions();
    if (isset($versions[$version])) {
        return $version;
    }
    foreach ($versions as $ver => $info) {
        if (runtime_state($info['key'])['installed']) {
            return (string)$ver;
        }
    }
    return '8.3';
}

function env_php_redirect(string $key, string $type, string $message): void
{
    flash($type, $message);
    redirect('/env/php?ver=' . urlencode(env_version_from_key($key)));
}

/* -------------------------------- PHP -------------------------------- */

function env_php_page(): void
{
    $version = env_pick_php_version((string)($_GET['ver'] ?? ''));
    $key = runtime_php_key($version);
    $text = php_ini_read($key);
    $path = php_ini_path($key);
    $state = runtime_state($key);

    $versions = [];
    foreach (runtime_php_versions() as $ver => $info) {
        $st = runtime_state($info['key']);
        $versions[] = [
            'version' => (string)$ver,
            'key' => $info['key'],
            'label' => $info['label'],
            'installed' => $st['installed'],
            'running' => $st['running'],
            'port' => $st['port'],
        ];
    }

    render('env_php', [
        'title' => 'PHP 配置',
        'active' => 'env_php',
        'version' => $version,
        'key' => $key,
        'state' => $state,
        'ini_path' => $path,
        'ini_text' => $text,
        'knob_groups' => php_ini_knobs($text),
        'extensions' => php_ext_items($key),
        'versions' => $versions,
        'backups' => $path ? env_backup_list($path, 'php-ini') : [],
        'ini_errors' => ini_syntax_errors($text),
    ], 'app');
}

function env_php_save(): void
{
    $key = (string)($_POST['key'] ?? '');
    if (!isset(runtime_catalog()[$key]) || !str_starts_with($key, 'php')) {
        flash('error', '未知的 PHP 版本');
        redirect('/env/php');
    }
    $values = [];
    foreach (php_ini_knob_groups() as $items) {
        foreach ($items as $name => $meta) {
            if (array_key_exists($name, $_POST)) {
                $values[$name] = (string)$_POST[$name];
            }
        }
    }
    $result = php_ini_apply($key, $values, !empty($_POST['restart']));
    env_php_redirect($key, $result['ok'] ? 'success' : 'error', $result['message']);
}

function env_php_raw(): void
{
    $key = (string)($_POST['key'] ?? '');
    if (!isset(runtime_catalog()[$key]) || !str_starts_with($key, 'php')) {
        flash('error', '未知的 PHP 版本');
        redirect('/env/php');
    }
    $content = (string)($_POST['content'] ?? '');
    if (trim($content) === '') {
        env_php_redirect($key, 'error', 'php.ini 内容不能为空');
    }
    $result = php_ini_commit($key, $content, 'php-ini', !empty($_POST['restart']), []);
    env_php_redirect($key, $result['ok'] ? 'success' : 'error', $result['message']);
}

function env_php_extensions(): void
{
    $key = (string)($_POST['key'] ?? '');
    if (!isset(runtime_catalog()[$key]) || !str_starts_with($key, 'php')) {
        flash('error', '未知的 PHP 版本');
        redirect('/env/php');
    }
    $want = $_POST['extensions'] ?? [];
    $result = php_ext_apply($key, is_array($want) ? $want : []);
    env_php_redirect($key, $result['ok'] ? 'success' : 'error', $result['message']);
}

function env_php_restart(): void
{
    $key = (string)($_POST['key'] ?? '');
    if (!isset(runtime_catalog()[$key]) || !str_starts_with($key, 'php')) {
        flash('error', '未知的 PHP 版本');
        redirect('/env/php');
    }
    $result = runtime_state($key)['running'] ? runtime_restart($key) : runtime_start($key);
    log_action('重启 PHP', runtime_package($key)['name'], 'warning');
    env_php_redirect($key, $result['ok'] ? 'success' : 'error', $result['message']);
}

/* ------------------------------- Nginx ------------------------------- */

function env_nginx_page(): void
{
    $confPath = nginx_conf_path();
    $content = $confPath ? (string)@file_get_contents($confPath) : '';
    $knobs = nginx_knobs_read($content);
    $schema = nginx_knob_defaults();

    render('env_nginx', [
        'title' => 'Nginx 配置',
        'active' => 'env_nginx',
        'installed' => $confPath !== null,
        'conf_path' => $confPath,
        'content' => $content,
        'knobs' => $knobs,
        'schema' => $schema,
        'zones' => nginx_zones_content(),
        'zones_path' => nginx_zones_file(),
        'running' => managed_nginx_installed() && runtime_state('nginx')['running'],
        'version' => nginx_version(),
        'backups' => $confPath ? env_backup_list($confPath, 'nginx-conf') : [],
        'test' => $confPath ? nginx_conf_test() : ['ok' => false, 'message' => '未安装托管 Nginx'],
        'web_port' => active_web_port(),
        'limit_sites' => function_exists('fetch_all')
            ? fetch_all('SELECT name, domain, rate_limit, rate_burst, conn_limit FROM ' . t('sites') . ' WHERE rate_limit > 0 OR conn_limit > 0')
            : [],
    ], 'app');
}

function env_nginx_save(): void
{
    $result = nginx_conf_apply_knobs($_POST);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/env/nginx');
}

function env_nginx_raw(): void
{
    $content = (string)($_POST['content'] ?? '');
    if (trim($content) === '') {
        flash('error', '配置内容不能为空');
        redirect('/env/nginx');
    }
    $result = nginx_conf_apply($content);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/env/nginx');
}

function env_nginx_test(): void
{
    $test = nginx_conf_test();
    flash($test['ok'] ? 'success' : 'error', $test['ok'] ? 'nginx -t 校验通过' : 'nginx -t 失败：' . $test['message']);
    redirect('/env/nginx');
}

function env_nginx_rollback(): void
{
    $file = (string)($_POST['backup'] ?? '');
    $result = nginx_conf_restore(env_backup_dir() . '/' . basename($file));
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/env/nginx');
}

function env_nginx_restart(): void
{
    $result = runtime_state('nginx')['running'] ? runtime_restart('nginx') : runtime_start('nginx');
    log_action('重启 Nginx', '托管 Nginx', 'warning');
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/env/nginx');
}

/* -------------------------------- MySQL -------------------------------- */

function env_mysql_page(): void
{
    $status = mysql_env_status();
    $vars = mysql_env_variables();
    $schema = mysql_env_tunable_schema();
    $tunables = [];
    foreach ($schema as $name => $meta) {
        $tunables[] = [
            'name' => $name,
            'label' => $meta['label'],
            'type' => $meta['type'],
            'hint' => $meta['hint'],
            'value' => (string)($vars[$name] ?? ''),
        ];
    }
    $details = [];
    foreach (['version_comment', 'datadir', 'basedir', 'log_error', 'slow_query_log_file', 'innodb_version', 'character_set_server', 'collation_server', 'sql_mode'] as $name) {
        if (isset($vars[$name])) {
            $details[$name] = $vars[$name];
        }
    }

    render('env_mysql', [
        'title' => 'MySQL 状态',
        'active' => 'env_mysql',
        'status' => $status,
        'tunables' => $tunables,
        'details' => $details,
        'processes' => $status['ok'] ? mysql_env_processes() : [],
    ], 'app');
}

function env_mysql_variable(): void
{
    $name = (string)($_POST['name'] ?? '');
    $value = (string)($_POST['value'] ?? '');
    $result = mysql_env_set_variable($name, $value);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/env/mysql');
}

function env_mysql_kill(): void
{
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        flash('error', '连接 ID 不合法');
        redirect('/env/mysql');
    }
    $result = mysql_env_kill($id);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/env/mysql');
}

/* -------------------------------- Redis -------------------------------- */

function env_redis_page(): void
{
    $target = redis_target();
    $info = redis_info_all();
    $pattern = (string)($_GET['pattern'] ?? '*');
    $limit = (int)($_GET['limit'] ?? 100);
    $scan = ['ok' => true, 'keys' => [], 'total' => 0, 'message' => ''];
    $dbsize = null;
    if ($info['ok'] && ($pattern !== '' || $limit > 0)) {
        $scan = redis_scan($pattern, $limit);
        $size = redis_cmd(['DBSIZE']);
        $dbsize = $size['ok'] ? (int)$size['data'] : null;
    }

    $flat = $info['flat'];
    $memory = [
        'used' => (int)($flat['used_memory'] ?? 0),
        'peak' => (int)($flat['used_memory_peak'] ?? 0),
        'rss' => (int)($flat['used_memory_rss'] ?? 0),
        'maxmemory' => (int)($flat['maxmemory'] ?? 0),
        'policy' => (string)($flat['maxmemory_policy'] ?? ''),
        'fragmentation' => (string)($flat['mem_fragmentation_ratio'] ?? ''),
    ];
    $stats = [
        'version' => (string)($flat['redis_version'] ?? ''),
        'uptime' => (int)($flat['uptime_in_seconds'] ?? 0),
        'clients' => (int)($flat['connected_clients'] ?? 0),
        'ops' => (int)($flat['instantaneous_ops_per_sec'] ?? 0),
        'hits' => (int)($flat['keyspace_hits'] ?? 0),
        'misses' => (int)($flat['keyspace_misses'] ?? 0),
        'evicted' => (int)($flat['evicted_keys'] ?? 0),
        'expired' => (int)($flat['expired_keys'] ?? 0),
        'total_commands' => (int)($flat['total_commands_processed'] ?? 0),
    ];
    $hitRate = ($stats['hits'] + $stats['misses']) > 0
        ? round($stats['hits'] / ($stats['hits'] + $stats['misses']) * 100, 2)
        : 0.0;

    render('env_redis', [
        'title' => 'Redis 管理',
        'active' => 'env_redis',
        'target' => $target,
        'info' => $info,
        'memory' => $memory,
        'stats' => $stats,
        'hit_rate' => $hitRate,
        'dbsize' => $dbsize,
        'pattern' => $pattern,
        'limit' => $limit,
        'scan' => $scan,
        'sections' => $info['sections'],
    ], 'app');
}

function env_redis_delete(): void
{
    $keys = $_POST['keys'] ?? [];
    $result = redis_delete_keys(is_array($keys) ? $keys : []);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    $pattern = (string)($_POST['pattern'] ?? '*');
    redirect('/env/redis?pattern=' . urlencode($pattern));
}

function env_redis_flush(): void
{
    $result = redis_flush_db();
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/env/redis');
}
