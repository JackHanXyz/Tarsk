<?php
declare(strict_types=1);

/**
 * 告警自愈：站点巡检失败时，按固定顺序尝试修复（重建 vhost → 重启 PHP → 重启 Nginx），
 * 修复后重新探活，成功则只记录不通知，仍失败才交给告警渠道。
 *
 * 保护：全局开关（settings.self_heal_enabled）、每站点冷却（默认 10 分钟）、
 * 每小时最多 N 次（默认 3 次），避免故障恶化时反复重启。
 */

function self_heal_enabled(): bool
{
    return (string)setting('self_heal_enabled', '0') === '1';
}

function self_heal_cooldown(): int
{
    return max(60, (int)setting('self_heal_cooldown', '600'));
}

function self_heal_max_per_hour(): int
{
    return max(1, (int)setting('self_heal_max_hour', '3'));
}

/**
 * 冷却与频率检查
 *
 * @return array{ok: bool, reason: string}
 */
function self_heal_allow(string $key): array
{
    $state = json_decode((string)setting('self_heal_state', '{}'), true);
    if (!is_array($state)) {
        $state = [];
    }
    $now = time();
    $entry = $state[$key] ?? ['at' => 0, 'hour_start' => 0, 'hour_count' => 0];
    if ($now - (int)$entry['at'] < self_heal_cooldown()) {
        $wait = self_heal_cooldown() - ($now - (int)$entry['at']);
        return ['ok' => false, 'reason' => '冷却中，还需等待 ' . ceil($wait / 60) . ' 分钟'];
    }
    if ($now - (int)$entry['hour_start'] >= 3600) {
        $entry['hour_start'] = $now;
        $entry['hour_count'] = 0;
    }
    if ((int)$entry['hour_count'] >= self_heal_max_per_hour()) {
        return ['ok' => false, 'reason' => '本小时已达自愈上限 ' . self_heal_max_per_hour() . ' 次'];
    }
    return ['ok' => true, 'reason' => ''];
}

function self_heal_mark(string $key): void
{
    $state = json_decode((string)setting('self_heal_state', '{}'), true);
    if (!is_array($state)) {
        $state = [];
    }
    $now = time();
    $entry = $state[$key] ?? ['at' => 0, 'hour_start' => $now, 'hour_count' => 0];
    if ($now - (int)$entry['hour_start'] >= 3600) {
        $entry['hour_start'] = $now;
        $entry['hour_count'] = 0;
    }
    $entry['at'] = $now;
    $entry['hour_count'] = (int)$entry['hour_count'] + 1;
    $state[$key] = $entry;
    setting_set('self_heal_state', json_encode($state));
}

/**
 * 探活：通过 Host 头访问站点首页，返回 HTTP 状态码（0 表示超时/连接失败）
 */
function site_probe_status(array $site, int $port = 0): int
{
    $port = $port > 0 ? $port : active_web_port();
    $host = trim((string)$site['domain']) !== '' ? (string)$site['domain'] : (string)$site['name'] . '.local';
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Host: " . $host . "\r\nUser-Agent: Tarsk-Uptime/1.0\r\n",
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ]);
    @file_get_contents('http://127.0.0.1:' . $port . '/', false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int)$m[1];
        }
    }
    return $status;
}

/**
 * 尝试修复单个站点
 *
 * @return array{ok: bool, steps: string[], message: string, status: int}
 */
function self_heal_site(array $site): array
{
    $key = 'site:' . (int)$site['id'];
    $allow = self_heal_allow($key);
    if (!$allow['ok']) {
        return ['ok' => false, 'steps' => [], 'message' => $allow['reason'], 'status' => 0];
    }
    self_heal_mark($key);

    $steps = [];
    try {
        // 1. 重建 vhost（配置损坏/缺失时最常见的原因）
        if ((string)$site['status'] === 'running' && function_exists('write_site_vhost')) {
            $r = write_site_vhost($site);
            $steps[] = '重建 vhost：' . ($r['ok'] ? '成功' : '失败（' . $r['message'] . '）');
        }
        // 2. 重启 PHP-CGI（502/504 大多由它引起）
        if ((int)($site['php_port'] ?? 0) > 0 && function_exists('runtime_restart')) {
            $state = runtime_state('php');
            $r = runtime_restart('php');
            $steps[] = '重启 PHP：' . (!empty($r['ok']) ? '成功' : '失败（' . ($r['message'] ?? '未知') . '）');
        }
        // 3. Nginx 未运行或未加载新配置时重启
        if (function_exists('runtime_state') && function_exists('runtime_restart')) {
            $nginx = runtime_state('nginx');
            if (empty($nginx['running'])) {
                $r = runtime_restart('nginx');
                $steps[] = '重启 Nginx：' . (!empty($r['ok']) ? '成功' : '失败（' . ($r['message'] ?? '未知') . '）');
            }
        }
    } catch (Throwable $e) {
        $steps[] = '异常：' . $e->getMessage();
    }

    // 修复后重新探活，给服务一点启动时间
    sleep(2);
    $status = site_probe_status($site);
    $recovered = $status >= 200 && $status < 400;

    return [
        'ok' => $recovered,
        'steps' => $steps,
        'message' => $recovered ? '自愈成功' : '自愈后仍不可用（HTTP ' . ($status > 0 ? $status : '超时') . '）',
        'status' => $status,
    ];
}
