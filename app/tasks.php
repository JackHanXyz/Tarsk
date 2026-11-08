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
function task_execute(array $task): array
{
    $type = (string)($task['type'] ?? 'command');
    $payload = task_payload($task);

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
            ];
            break;
    }

    $line = '[' . now() . '] #' . (int)$task['id'] . ' ' . $task['name'] . ' => ' . ($result['ok'] ? 'OK' : 'FAIL') . ' ' . $result['message'];
    @file_put_contents(storage_path('logs/task.log'), $line . "\n", FILE_APPEND);

    return $result;
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
