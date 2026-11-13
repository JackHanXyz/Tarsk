<?php
declare(strict_types=1);

/**
 * REST API：Token 管理 + /api/v1 只读与常用运维接口
 *
 * 认证：Authorization: Bearer tk_xxxxxxxx
 * scope：read（GET） / write（含 read，可执行变更操作）
 */

function api_scope_labels(): array
{
    return [
        'read' => '只读（查询状态 / 站点 / 数据库 / 告警）',
        'write' => '读写（含建站开关、备份、执行任务等变更操作）',
    ];
}

function api_token_create(string $name, string $scope, ?string $expiresAt = null): array
{
    if (!array_key_exists($scope, api_scope_labels())) {
        return ['ok' => false, 'message' => '范围不合法', 'token' => ''];
    }
    $name = trim($name) !== '' ? mb_substr(trim($name), 0, 120) : '未命名 Token';
    $token = 'tk_' . bin2hex(random_bytes(20));
    $hash = hash('sha256', $token);
    $user = current_user();
    try {
        execute(
            'INSERT INTO ' . t('api_tokens') . ' (name, token_prefix, token_hash, scopes, user_id, expires_at, created_at) VALUES (?,?,?,?,?,?,?)',
            [$name, substr($token, 0, 10), $hash, $scope, (int)($user['id'] ?? 0), $expiresAt, now()]
        );
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => '创建失败：' . $e->getMessage(), 'token' => ''];
    }
    log_action('创建 API Token', $name . ' · ' . $scope, 'warning');
    return ['ok' => true, 'message' => 'Token 已创建，请立即复制（只显示一次）', 'token' => $token];
}

function api_token_list(): array
{
    $rows = fetch_all('SELECT * FROM ' . t('api_tokens') . ' ORDER BY id DESC');
    $now = time();
    foreach ($rows as &$row) {
        $row['expired'] = !empty($row['expires_at']) && strtotime((string)$row['expires_at']) < $now;
    }
    unset($row);
    return $rows;
}

function api_token_delete(int $id): array
{
    $row = fetch_one('SELECT * FROM ' . t('api_tokens') . ' WHERE id = ?', [$id]);
    if (!$row) {
        return ['ok' => false, 'message' => 'Token 不存在'];
    }
    execute('DELETE FROM ' . t('api_tokens') . ' WHERE id = ?', [$id]);
    log_action('吊销 API Token', (string)$row['name'], 'danger');
    return ['ok' => true, 'message' => 'Token 已吊销：' . $row['name']];
}

/**
 * 从请求头取 Bearer Token
 */
function api_bearer_token(): string
{
    $header = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = (string)$_SERVER['HTTP_AUTHORIZATION'];
    } elseif (function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (strtolower((string)$key) === 'authorization') {
                $header = (string)$value;
                break;
            }
        }
    }
    if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
        return $m[1];
    }
    return '';
}

function api_authenticate(): ?array
{
    $token = api_bearer_token();
    if ($token === '') {
        return null;
    }
    $row = fetch_one('SELECT * FROM ' . t('api_tokens') . ' WHERE token_hash = ?', [hash('sha256', $token)]);
    if (!$row) {
        return null;
    }
    if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) < time()) {
        return null;
    }
    // 记录使用情况（失败不影响请求）
    try {
        execute('UPDATE ' . t('api_tokens') . ' SET last_used_at = ?, last_ip = ? WHERE id = ?', [now(), client_ip(), (int)$row['id']]);
    } catch (Throwable $e) {
        // 忽略
    }
    return $row;
}

function api_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_require_scope(array $token, string $scope): void
{
    $scopes = array_map('trim', explode(',', (string)($token['scopes'] ?? 'read')));
    if (!in_array($scope, $scopes, true) && !in_array('write', $scopes, true)) {
        api_respond(['ok' => false, 'error' => 'insufficient_scope', 'message' => '该 Token 没有 ' . $scope . ' 权限'], 403);
    }
}

function api_nginx_is_running(): bool
{
    $managedRunning = function_exists('managed_nginx_installed')
        && managed_nginx_installed()
        && (bool)(runtime_state('nginx')['running'] ?? false);
    return $managedRunning || (function_exists('service_running') && service_running('nginx', true));
}

/** 应用站点反向代理：配置测试/重载成功后才提交数据库，失败恢复原 vhost。 */
function api_site_proxy_apply(int $id, array $token): void
{
    api_require_scope($token, 'write');
    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    if (!array_key_exists('enabled', $payload)) {
        api_respond(['ok' => false, 'error' => 'validation_error', 'message' => 'enabled 字段必填'], 422);
    }
    $enabled = filter_var($payload['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($enabled === null) {
        api_respond(['ok' => false, 'error' => 'validation_error', 'message' => 'enabled 必须为布尔值'], 422);
    }
    $site = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$id]);
    if (!$site) {
        api_respond(['ok' => false, 'error' => 'not_found', 'message' => '站点不存在'], 404);
    }
    if (($site['status'] ?? '') !== 'running') {
        api_respond(['ok' => false, 'error' => 'conflict', 'message' => '请先启动站点，再应用反向代理'], 409);
    }
    if (!api_nginx_is_running() || !function_exists('runtime_nginx_reload') || !function_exists('nginx_conf_test')) {
        api_respond(['ok' => false, 'error' => 'nginx_unavailable', 'message' => '需要运行中的 Nginx 和配置测试能力'], 409);
    }
    $target = trim((string)($payload['target'] ?? ''));
    if ($enabled && site_proxy_target(['proxy_enabled' => 1, 'proxy_pass' => $target]) === '') {
        api_respond(['ok' => false, 'error' => 'validation_error', 'message' => '代理地址无效，应为 http(s)://主机[:端口][/路径]'], 422);
    }
    $candidate = $site;
    $candidate['proxy_enabled'] = $enabled ? 1 : 0;
    $candidate['proxy_pass'] = $enabled ? $target : (string)($site['proxy_pass'] ?? '');
    $conf = site_conf_path((string)$site['name']);
    $hadConf = is_file($conf);
    $previousConf = $hadConf ? (string)@file_get_contents($conf) : '';
    $restore = static function () use ($conf, $hadConf, $previousConf): void {
        if ($hadConf) {
            @file_put_contents($conf, $previousConf, LOCK_EX);
        } else {
            @unlink($conf);
        }
        try { runtime_nginx_reload(); } catch (Throwable $ignored) {}
    };
    try {
        $written = write_site_vhost($candidate, false);
        $test = $written['ok'] ? nginx_conf_test() : ['ok' => false, 'message' => $written['message'] ?? '写入 vhost 失败'];
    } catch (Throwable $e) {
        $restore();
        api_respond(['ok' => false, 'error' => 'site_apply_failed', 'message' => '代理配置应用异常，已恢复原配置'], 502);
    }
    if (empty($written['ok']) || empty($test['ok'])) {
        $restore();
        $message = empty($written['ok']) ? (string)($written['message'] ?? '站点配置写入失败') : (string)($test['message'] ?? 'Nginx 配置测试失败');
        api_respond(['ok' => false, 'error' => 'site_apply_failed', 'message' => '代理配置未应用，已恢复原配置：' . $message], 502);
    }
    try {
        if (!runtime_nginx_reload()) {
            $restore();
            api_respond(['ok' => false, 'error' => 'site_apply_failed', 'message' => 'Nginx 重载失败，已恢复原配置'], 502);
        }
        execute('UPDATE ' . t('sites') . ' SET proxy_enabled = ?, proxy_pass = ? WHERE id = ?',
            [$candidate['proxy_enabled'], $candidate['proxy_pass'], $id]);
    } catch (Throwable $e) {
        $restore();
        api_respond(['ok' => false, 'error' => 'site_apply_failed', 'message' => '保存代理状态失败，已恢复原配置'], 500);
    }
    log_action($enabled ? '配置站点反向代理' : '关闭站点反向代理', (string)$site['name'] . ($enabled ? ' → ' . $target : ''), 'warning');
    api_respond(['ok' => true, 'message' => $enabled ? '反向代理已应用' : '反向代理已关闭',
        'data' => ['id' => $id, 'proxy_enabled' => (bool)$candidate['proxy_enabled'], 'proxy_pass' => $candidate['proxy_pass']]]);
}

/**
 * /api/v1 统一入口：按路径分派
 */
function api_dispatch(string $method, string $path): void
{
    $token = api_authenticate();
    if ($token === null) {
        api_respond(['ok' => false, 'error' => 'unauthorized', 'message' => '缺少或无效的 Bearer Token'], 401);
    }
    $method = strtoupper($method);
    $segments = array_values(array_filter(explode('/', $path)));
    // ['api','v1', ...]
    $rest = array_slice($segments, 2);
    $route = $rest[0] ?? '';

    try {
        switch ($route) {
            case 'status':
                api_require_scope($token, 'read');
                $metrics = system_metrics();
                api_respond(['ok' => true, 'data' => [
                    'panel_version' => (string)config('app.version', ''),
                    'hostname' => (string)($metrics['hostname'] ?? ''),
                    'cpu' => (float)($metrics['cpu'] ?? 0),
                    'mem_used' => (int)($metrics['mem_used'] ?? 0),
                    'mem_total' => (int)($metrics['mem_total'] ?? 0),
                    'disk_used' => (int)($metrics['disk_used'] ?? 0),
                    'disk_total' => (int)($metrics['disk_total'] ?? 0),
                    'uptime' => (int)($metrics['uptime'] ?? 0),
                    'sites' => (int)(fetch_one('SELECT COUNT(*) AS n FROM ' . t('sites'))['n'] ?? 0),
                    'nginx_running' => api_nginx_is_running(),
                ]]);

            case 'sites':
                api_require_scope($token, 'read');
                if ($method === 'GET' && count($rest) === 2 && ctype_digit((string)$rest[1])) {
                    $site = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [(int)$rest[1]]);
                    if (!$site) {
                        api_respond(['ok' => false, 'error' => 'not_found', 'message' => '站点不存在'], 404);
                    }
                    api_respond(['ok' => true, 'data' => [
                        'id' => (int)$site['id'], 'name' => (string)$site['name'], 'domain' => (string)$site['domain'],
                        'domains' => (string)($site['domains'] ?? ''), 'path' => (string)$site['path'],
                        'php_version' => (string)($site['php_version'] ?? ''), 'port' => site_effective_port($site),
                        'status' => (string)$site['status'], 'ssl_enabled' => (bool)($site['ssl_enabled'] ?? false),
                        'proxy_enabled' => (bool)($site['proxy_enabled'] ?? false), 'proxy_pass' => (string)($site['proxy_pass'] ?? ''),
                        'rewrite' => (string)($site['rewrite'] ?? ''), 'remark' => (string)($site['remark'] ?? ''),
                    ]]);
                }
                if ($method === 'GET' && count($rest) === 1) {
                    $rows = fetch_all('SELECT id, name, domain, status, php_version, port, ssl_enabled, proxy_enabled FROM ' . t('sites') . ' ORDER BY id');
                    api_respond(['ok' => true, 'data' => $rows]);
                }
                if ($method === 'POST' && count($rest) === 3 && ctype_digit((string)$rest[1]) && $rest[2] === 'proxy') {
                    api_site_proxy_apply((int)$rest[1], $token);
                }
                if ($method === 'POST' && count($rest) === 3 && $rest[2] === 'toggle') {
                    api_require_scope($token, 'write');
                    $id = (int)$rest[1];
                    $site = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$id]);
                    if (!$site) {
                        api_respond(['ok' => false, 'error' => 'not_found', 'message' => '站点不存在'], 404);
                    }
                    $next = $site['status'] === 'running' ? 'stopped' : 'running';
                    $candidate = $site;
                    $candidate['status'] = $next;
                    $result = $next === 'running' ? write_site_vhost($candidate) : remove_site_vhost($candidate);
                    if (empty($result['ok'])) {
                        $message = (string)($result['message'] ?? 'Nginx 站点配置应用失败');
                        log_action('API 切换站点状态失败', $site['name'] . ' -> ' . $next . ' · ' . $message, 'warning');
                        api_respond([
                            'ok' => false,
                            'error' => 'site_apply_failed',
                            'message' => $message,
                            'data' => ['id' => $id, 'status' => $site['status']],
                        ], 502);
                    }
                    execute('UPDATE ' . t('sites') . ' SET status = ? WHERE id = ?', [$next, $id]);
                    log_action('API 切换站点状态', $site['name'] . ' -> ' . $next, 'warning');
                    api_respond(['ok' => true, 'data' => ['id' => $id, 'status' => $next, 'message' => $result['message'] ?? '站点状态已更新']]);
                }
                api_respond(['ok' => false, 'error' => 'bad_request', 'message' => '不支持的站点接口'], 400);

            case 'databases':
                api_require_scope($token, 'read');
                $dbs = [];
                foreach (mysql_databases() as $db) {
                    $dbs[] = ['name' => $db['name'], 'tables' => (int)($db['tables'] ?? 0), 'bytes' => (int)($db['bytes'] ?? 0)];
                }
                api_respond(['ok' => true, 'data' => $dbs]);

            case 'backups':
                if ($method === 'POST' && count($rest) >= 2) {
                    api_require_scope($token, 'write');
                    $kind = $rest[1];
                    $payload = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
                    if ($kind === 'site') {
                        $siteId = (int)($payload['site_id'] ?? 0);
                        $result = backup_site_create($siteId);
                    } elseif ($kind === 'db') {
                        $result = backup_db_create((string)($payload['database'] ?? ''));
                    } else {
                        api_respond(['ok' => false, 'error' => 'bad_request', 'message' => '备份类型仅支持 site / db'], 400);
                    }
                    api_respond(['ok' => (bool)$result['ok'], 'data' => $result], $result['ok'] ? 200 : 500);
                }
                api_require_scope($token, 'read');
                $list = [];
                foreach (backup_list() as $item) {
                    $list[] = [
                        'name' => $item['name'],
                        'kind' => $item['kind'],
                        'target' => $item['target'] ?? '',
                        'size' => $item['size'],
                        'time' => date('Y-m-d H:i:s', (int)$item['mtime']),
                    ];
                }
                api_respond(['ok' => true, 'data' => $list]);

            case 'tasks':
                api_require_scope($token, 'read');
                if ($method === 'GET' && count($rest) === 1) {
                    $rows = fetch_all('SELECT id, name, type, schedule, cron_expr, status, last_run_at, last_result FROM ' . t('cron') . ' ORDER BY id');
                    api_respond(['ok' => true, 'data' => $rows]);
                }
                if ($method === 'POST' && count($rest) === 3 && $rest[2] === 'run') {
                    api_require_scope($token, 'write');
                    $task = fetch_one('SELECT * FROM ' . t('cron') . ' WHERE id = ?', [(int)$rest[1]]);
                    if (!$task) {
                        api_respond(['ok' => false, 'error' => 'not_found', 'message' => '任务不存在'], 404);
                    }
                    $result = task_execute($task, 'manual');
                    execute('UPDATE ' . t('cron') . ' SET last_run_at = ?, last_result = ? WHERE id = ?',
                        [now(), mb_substr($result['message'], 0, 255), (int)$task['id']]);
                    api_respond(['ok' => (bool)$result['ok'], 'data' => $result], $result['ok'] ? 200 : 500);
                }
                api_respond(['ok' => false, 'error' => 'bad_request', 'message' => '不支持的任务接口'], 400);

            case 'alerts':
                api_require_scope($token, 'read');
                $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
                $rows = fetch_all('SELECT id, kind, level, title, message, metric, value, threshold, created_at FROM ' . t('alerts') . ' ORDER BY id DESC LIMIT ' . $limit);
                api_respond(['ok' => true, 'data' => $rows]);

            case 'nginx':
                if ($method === 'POST' && ($rest[1] ?? '') === 'reload') {
                    api_require_scope($token, 'write');
                    $running = api_nginx_is_running();
                    $ok = runtime_nginx_reload();
                    log_action('API 重载 Nginx', $ok ? '成功' : '失败', $ok ? 'info' : 'warning');
                    api_respond([
                        'ok' => $ok,
                        'data' => ['reloaded' => $ok, 'running' => $running],
                        'message' => $ok ? '已重载' : ($running ? '重载命令执行失败' : 'Nginx 未运行，无法重载'),
                    ], $ok ? 200 : 409);
                }
                api_require_scope($token, 'read');
                $test = nginx_conf_test();
                api_respond(['ok' => true, 'data' => ['running' => api_nginx_is_running(), 'test' => $test]]);

            default:
                api_respond(['ok' => false, 'error' => 'not_found', 'message' => '未知接口：' . $path], 404);
        }
    } catch (Throwable $e) {
        api_respond(['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()], 500);
    }
}
