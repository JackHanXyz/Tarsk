<?php
declare(strict_types=1);

/**
 * 计划任务执行器：自定义命令 / 站点备份 / 数据库备份 / 日志切割 / 证书续期
 */

function task_types(): array
{
    return [
        'command' => '自定义命令',
        'site_backup' => '站点备份',
        'db_backup' => '数据库备份',
        'log_cut' => '站点日志切割',
        'cert_renew' => "Let's Encrypt 证书续期",
        'site_check' => '站点存活巡检',
    ];
}

function task_payload(array $task): array
{
    $raw = (string)($task['payload'] ?? '');
    if ($raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * 生成供系统调度器执行的固定命令（逻辑在 bin/task.php 内按类型分派）
 */
function task_command(array $task): string
{
    $php = PHP_BINARY;
    if (PHP_OS_FAMILY !== 'Windows' && $php === '') {
        $php = 'php';
    }
    $script = app_root() . '/bin/task.php';
    $arg = ' --id=' . (int)$task['id'];
    if (PHP_OS_FAMILY === 'Windows') {
        return '"' . str_replace('/', '\\', $php) . '" "' . str_replace('/', '\\', $script) . '"' . $arg;
    }
    return escapeshellarg($php) . ' ' . escapeshellarg($script) . $arg;
}

function task_label(array $task): string
{
    $types = task_types();
    return $types[$task['type'] ?? 'command'] ?? '自定义命令';
}

/**
 * 执行任务，返回 ['ok'=>bool,'message'=>string]
 */
function task_execute(array $task, string $trigger = 'schedule'): array
{
    $type = (string)($task['type'] ?? 'command');
    $payload = task_payload($task);
    $startedAt = microtime(true);

    switch ($type) {
        case 'site_backup':
            $siteId = (int)($payload['site_id'] ?? 0);
            if ($siteId <= 0) {
                return ['ok' => false, 'message' => '未指定站点'];
            }
            $result = backup_site_create($siteId);
            break;

        case 'db_backup':
            $dbName = (string)($payload['db_name'] ?? '');
            if ($dbName === '') {
                return ['ok' => false, 'message' => '未指定数据库'];
            }
            $result = backup_db_create($dbName);
            break;

        case 'log_cut':
            $result = task_log_cut((int)($payload['site_id'] ?? 0));
            break;

        case 'cert_renew':
            $result = task_cert_renew();
            break;

        case 'site_check':
            $result = task_site_check((int)($payload['site_id'] ?? 0));
            break;

        case 'command':
        default:
            $command = trim((string)($payload['command'] ?? $task['command'] ?? ''));
            if ($command === '') {
                return ['ok' => false, 'message' => '执行命令为空'];
            }
            $res = PHP_OS_FAMILY === 'Windows'
                ? run_cmd('cmd /c ' . $command, 300)
                : run_cmd('bash -lc ' . escapeshellarg($command), 300);
            $result = [
                'ok' => $res['code'] === 0,
                'message' => $res['code'] === 0
                    ? '执行成功' . (trim($res['output']) !== '' ? '：' . mb_substr(trim($res['output']), 0, 180) : '')
                    : ('执行失败：' . mb_substr($res['error'] ?: $res['output'], 0, 180)),
                'output' => trim(($res['output'] ?? '') . "\n" . ($res['error'] ?? '')),
            ];
            break;
    }

    $line = '[' . now() . '] #' . (int)$task['id'] . ' ' . $task['name'] . ' => ' . ($result['ok'] ? 'OK' : 'FAIL') . ' ' . $result['message'];
    @file_put_contents(storage_path('logs/task.log'), $line . "\n", FILE_APPEND);

    cron_history_write((int)($task['id'] ?? 0), $result, (int)round((microtime(true) - $startedAt) * 1000), $trigger);

    return $result;
}

/* --------------------------- 执行历史 --------------------------- */

function cron_history_write(int $taskId, array $result, int $durationMs, string $trigger = 'schedule'): void
{
    if ($taskId <= 0) {
        return;
    }
    try {
        execute(
            'INSERT INTO ' . t('cron_logs') . ' (task_id, started_at, duration_ms, ok, trigger_by, message, output) VALUES (?,?,?,?,?,?,?)',
            [
                $taskId,
                now(),
                max(0, $durationMs),
                !empty($result['ok']) ? 1 : 0,
                in_array($trigger, ['schedule', 'manual'], true) ? $trigger : 'schedule',
                mb_substr((string)($result['message'] ?? ''), 0, 500),
                mb_substr((string)($result['output'] ?? ''), 0, 20000),
            ]
        );
        // 每个任务只保留最近 200 条
        $keepFrom = fetch_one(
            'SELECT id FROM ' . t('cron_logs') . ' WHERE task_id = ? ORDER BY id DESC LIMIT 1 OFFSET 199',
            [$taskId]
        );
        if ($keepFrom) {
            execute('DELETE FROM ' . t('cron_logs') . ' WHERE task_id = ? AND id < ?', [$taskId, (int)$keepFrom['id']]);
        }
    } catch (Throwable $e) {
        // 记录失败不影响任务本身
    }
}

/* --------------------------- 自定义 cron 表达式 --------------------------- */

/**
 * 支持 5 段式表达式，以及 @hourly / @daily / @weekly / @monthly / @reboot 预设
 */
function cron_expr_normalize(string $expr): string
{
    $expr = trim(preg_replace('/\s+/', ' ', $expr) ?? '');
    $presets = [
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly' => '0 * * * *',
        '@reboot' => '@reboot',
    ];
    $lower = strtolower($expr);
    if (isset($presets[$lower])) {
        return $presets[$lower];
    }
    return $expr;
}

function cron_expr_is_reboot(string $expr): bool
{
    return strtolower(cron_expr_normalize($expr)) === '@reboot';
}

/**
 * 校验 5 段式表达式，返回错误信息（空串表示合法）
 */
function cron_expr_error(string $expr): string
{
    $normalized = cron_expr_normalize($expr);
    if ($normalized === '') {
        return '表达式不能为空';
    }
    if ($normalized === '@reboot') {
        return '';
    }
    $fields = explode(' ', $normalized);
    if (count($fields) !== 5) {
        return '需要 5 段（分 时 日 月 周），当前 ' . count($fields) . ' 段';
    }
    $limits = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
    $names = ['分钟', '小时', '日', '月', '星期'];
    foreach ($fields as $i => $field) {
        $error = cron_field_error($field, $limits[$i][0], $limits[$i][1]);
        if ($error !== '') {
            return $names[$i] . '字段' . $error;
        }
    }
    return '';
}

function cron_field_error(string $field, int $min, int $max): string
{
    if ($field === '' ) {
        return '不能为空';
    }
    foreach (explode(',', $field) as $part) {
        if ($part === '') {
            return '存在空的枚举项';
        }
        $step = 1;
        if (strpos($part, '/') !== false) {
            [$part, $stepStr] = array_pad(explode('/', $part, 2), 2, '1');
            if (!ctype_digit($stepStr) || (int)$stepStr < 1) {
                return '步长不合法（' . $stepStr . '）';
            }
            $step = (int)$stepStr;
        }
        if ($part === '*') {
            continue;
        }
        if (strpos($part, '-') !== false) {
            [$from, $to] = array_pad(explode('-', $part, 2), 2, '');
            if (!ctype_digit($from) || !ctype_digit($to)) {
                return '区间不合法（' . $part . '）';
            }
            if ((int)$from < $min || (int)$to > $max || (int)$from > (int)$to) {
                return '区间超出范围 ' . $min . '-' . $max . '（' . $part . '）';
            }
            continue;
        }
        if (!ctype_digit($part)) {
            return '取值不合法（' . $part . '）';
        }
        if ((int)$part < $min || (int)$part > $max) {
            return '超出范围 ' . $min . '-' . $max . '（' . $part . '）';
        }
    }
    return '';
}

function cron_field_match(string $field, int $value, int $min, int $max): bool
{
    if ($field === '*' || $field === '') {
        return true;
    }
    foreach (explode(',', $field) as $part) {
        $step = 1;
        if (strpos($part, '/') !== false) {
            [$part, $stepStr] = array_pad(explode('/', $part, 2), 2, '1');
            $step = max(1, (int)$stepStr);
        }
        if ($part === '*') {
            $from = $min;
            $to = $max;
        } elseif (strpos($part, '-') !== false) {
            [$fromStr, $toStr] = array_pad(explode('-', $part, 2), 2, '');
            $from = (int)$fromStr;
            $to = (int)$toStr;
        } elseif (ctype_digit($part)) {
            $from = (int)$part;
            $to = $step > 1 ? $max : $from;
        } else {
            continue;
        }
        if ($value < $from || $value > $to) {
            continue;
        }
        if ($step > 1 && ($value - $from) % $step !== 0) {
            continue;
        }
        return true;
    }
    return false;
}

/**
 * 判断表达式在给定时间点是否应当触发
 */
function cron_expr_matches(string $expr, ?int $ts = null): bool
{
    $normalized = cron_expr_normalize($expr);
    if ($normalized === '' || $normalized === '@reboot') {
        return false;
    }
    $fields = explode(' ', $normalized);
    if (count($fields) !== 5) {
        return false;
    }
    $ts = $ts ?? time();
    $dow = (int)date('w', $ts);
    $domRestricted = !in_array($fields[2], ['*', ''], true);
    $dowRestricted = !in_array($fields[4], ['*', ''], true);

    if ($domRestricted && $dowRestricted) {
        // 日与周同时限定时，标准 cron 取"或"关系
        $dayOk = cron_field_match($fields[2], (int)date('j', $ts), 1, 31)
            || cron_field_match($fields[4], $dow, 0, 7)
            || ($dow === 0 && cron_field_match($fields[4], 7, 0, 7));
    } elseif ($domRestricted) {
        $dayOk = cron_field_match($fields[2], (int)date('j', $ts), 1, 31);
    } elseif ($dowRestricted) {
        $dayOk = cron_field_match($fields[4], $dow, 0, 7)
            || ($dow === 0 && cron_field_match($fields[4], 7, 0, 7));
    } else {
        $dayOk = true;
    }

    return cron_field_match($fields[0], (int)date('i', $ts), 0, 59)
        && cron_field_match($fields[1], (int)date('G', $ts), 0, 23)
        && $dayOk
        && cron_field_match($fields[3], (int)date('n', $ts), 1, 12);
}

/**
 * 站点日志切割：重命名当前日志到 logs/archive/ 并让 Nginx 重新打开日志文件
 */
function task_log_cut(int $siteId = 0): array
{
    $sites = $siteId > 0
        ? fetch_all('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$siteId])
        : fetch_all('SELECT * FROM ' . t('sites'));
    if (!$sites) {
        return ['ok' => false, 'message' => '没有可切割的站点日志'];
    }

    $root = managed_nginx_root();
    if ($root === null) {
        if (function_exists('find_nginx_root')) {
            $found = find_nginx_root();
            $root = $found !== '' ? $found : null;
        }
    }
    if ($root === null) {
        return ['ok' => false, 'message' => '未找到 Nginx 运行目录'];
    }
    $logDir = rtrim(str_replace('\\', '/', $root), '/') . '/logs';
    $archiveDir = $logDir . '/archive';
    if (!is_dir($archiveDir)) {
        @mkdir($archiveDir, 0777, true);
    }

    $cut = 0;
    $ts = date('Ymd_His');
    foreach ($sites as $site) {
        $log = $logDir . '/' . $site['name'] . '.log';
        if (!is_file($log) || filesize($log) === 0) {
            continue;
        }
        if (@rename($log, $archiveDir . '/' . $site['name'] . '_' . $ts . '.log')) {
            $cut++;
        }
    }

    $reopened = runtime_nginx_reopen();
    return [
        'ok' => $cut > 0,
        'message' => $cut > 0
            ? '已切割 ' . $cut . ' 个站点日志' . ($reopened ? '，Nginx 已重新打开日志文件' : '，请手动重载 Nginx')
            : '没有需要切割的日志（文件为空或不存在）',
    ];
}

/**
 * Let's Encrypt 证书自动续期：到期前 N 天重新签发并回写证书文件
 */
function task_cert_renew(): array
{
    if (!runtime_state('nginx')['running']) {
        return ['ok' => false, 'message' => '托管 Nginx 未运行，HTTP-01 验证无法完成'];
    }
    $days = max(1, (int)setting('cert_renew_days', '30'));
    $certs = fetch_all('SELECT * FROM ' . t('certs') . " WHERE source = 'letsencrypt'");
    if (!$certs) {
        return ['ok' => true, 'message' => '没有需要续期的 Let\'s Encrypt 证书'];
    }

    $renewed = 0;
    $failed = [];
    foreach ($certs as $cert) {
        $left = $cert['not_after'] ? (int)floor((strtotime($cert['not_after']) - time()) / 86400) : 0;
        if ($left > $days) {
            continue;
        }
        $site = $cert['site_id'] ? fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$cert['site_id']]) : null;
        if (!$site || !is_dir((string)$site['path'])) {
            $failed[] = $cert['domain'] . '（站点目录缺失）';
            continue;
        }
        $result = acme_issue([(string)$cert['domain']], (string)$site['path'], 'admin@' . $cert['domain']);
        if (!$result['ok']) {
            $failed[] = $cert['domain'] . '（' . $result['message'] . '）';
            continue;
        }
        execute(
            'UPDATE ' . t('certs') . ' SET cert_path = ?, key_path = ?, not_before = ?, not_after = ?, issuer = ? WHERE id = ?',
            [$result['cert'], $result['key'], now(), $result['expires'], "Let's Encrypt", $cert['id']]
        );
        write_site_vhost($site);
        $renewed++;
    }

    if ($failed) {
        return [
            'ok' => $renewed > 0,
            'message' => '续期 ' . $renewed . ' 张，失败 ' . count($failed) . ' 张：' . implode('；', array_slice($failed, 0, 3)),
        ];
    }
    return ['ok' => true, 'message' => $renewed > 0 ? '已续期 ' . $renewed . ' 张证书' : '所有证书都未进入续期窗口（剩余 > ' . $days . ' 天）'];
}

/**
 * 站点存活巡检：通过 Host 头访问站点首页，失败时发送通知
 */
function task_site_check(int $siteId = 0): array
{
    $sites = $siteId > 0
        ? fetch_all('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$siteId])
        : fetch_all('SELECT * FROM ' . t('sites') . " WHERE status = 'running'");
    if (!$sites) {
        return ['ok' => false, 'message' => '没有需要巡检的站点'];
    }

    $port = active_web_port();
    $okCount = 0;
    $failures = [];
    $healed = [];
    foreach ($sites as $site) {
        $status = function_exists('site_probe_status')
            ? site_probe_status($site, $port)
            : 0;
        if ($status >= 200 && $status < 400) {
            $okCount++;
            continue;
        }

        $entry = $site['name'] . '（HTTP ' . ($status > 0 ? $status : '超时') . '）';

        // 告警自愈：重建 vhost / 重启 PHP / 重启 Nginx，再探一次
        if (function_exists('self_heal_site') && self_heal_enabled()) {
            $heal = self_heal_site($site);
            if ($heal['ok']) {
                $healed[] = $site['name'] . '（' . implode('；', $heal['steps']) . '）';
                log_action('站点自愈', $site['name'] . '：' . implode('；', $heal['steps']), 'warning');
                continue;
            }
            if ($heal['steps']) {
                $entry .= '；自愈失败：' . $heal['message'] . '（' . implode('；', $heal['steps']) . '）';
            } else {
                $entry .= '；未自愈：' . $heal['message'];
            }
        }

        $failures[] = $entry;
    }

    $healNote = $healed ? '；已自动修复：' . implode('；', $healed) : '';
    if ($failures) {
        $message = '以下站点巡检失败：' . implode('；', $failures) . $healNote;
        $notify = notify('站点巡检失败', $message, 'danger', ['kind' => 'site']);
        return [
            'ok' => false,
            'message' => $message . ($notify ? '（已发送通知）' : ''),
        ];
    }
    return ['ok' => true, 'message' => '全部 ' . $okCount . ' 个站点访问正常' . $healNote];
}

/**
 * 即将到期的 Let's Encrypt 证书（供页面提醒）
 */
function certs_expiring(int $withinDays = 30): array
{
    $items = [];
    foreach (fetch_all('SELECT * FROM ' . t('certs') . " WHERE source = 'letsencrypt'") as $cert) {
        $left = $cert['not_after'] ? (int)floor((strtotime($cert['not_after']) - time()) / 86400) : -1;
        if ($left <= $withinDays) {
            $cert['days_left'] = $left;
            $items[] = $cert;
        }
    }
    return $items;
}
