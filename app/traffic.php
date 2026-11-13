<?php
declare(strict_types=1);

/**
 * 站点流量统计：增量解析各站点 Nginx access log（combined 格式），
 * 按天累计响应字节数，供站点列表展示与月配额告警使用。
 */

/**
 * 每个站点的日志读取偏移，存 settings，避免重复扫描整个文件
 */
function traffic_offsets(): array
{
    $raw = (string)setting('traffic_offsets', '{}');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function traffic_save_offsets(array $offsets): void
{
    setting_set('traffic_offsets', json_encode($offsets));
}

/**
 * 增量采集；内部按 5 分钟节流，单次每站点最多处理 20000 行，避免拖慢请求
 *
 * @return array{ok: bool, lines: int, sites: int}
 */
function traffic_collect(bool $force = false): array
{
    $last = (int)setting('traffic_collect_at', '0');
    if (!$force && time() - $last < 300) {
        return ['ok' => true, 'lines' => 0, 'sites' => 0, 'skipped' => true];
    }

    $sites = fetch_all('SELECT id, name FROM ' . t('sites'));
    $offsets = traffic_offsets();
    $daily = [];          // [siteId][Y-m-d] = [bytes, hits]
    $totalLines = 0;
    $touched = 0;

    foreach ($sites as $site) {
        $path = function_exists('site_log_path') ? site_log_path($site) : '';
        if ($path === '' || !is_file($path)) {
            continue;
        }
        $size = (int)@filesize($path);
        $offset = (int)($offsets[$site['id']] ?? 0);
        if ($size < $offset) {
            $offset = 0; // 日志被轮转或截断
        }
        if ($size === $offset) {
            continue;
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            continue;
        }
        fseek($fh, $offset);
        $lines = 0;
        while (($line = fgets($fh)) !== false) {
            $lines++;
            if ($lines > 20000) {
                break;
            }
            $parsed = traffic_parse_line($line);
            if ($parsed === null) {
                continue;
            }
            $sid = (int)$site['id'];
            $day = $parsed['day'];
            if (!isset($daily[$sid][$day])) {
                $daily[$sid][$day] = ['bytes' => 0, 'hits' => 0];
            }
            $daily[$sid][$day]['bytes'] += $parsed['bytes'];
            $daily[$sid][$day]['hits']++;
        }
        $offsets[$site['id']] = ftell($fh);
        fclose($fh);
        $totalLines += $lines;
        $touched++;
    }

    foreach ($daily as $sid => $days) {
        foreach ($days as $day => $agg) {
            execute(
                'INSERT INTO ' . t('traffic') . ' (site_id, day, bytes, hits) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE bytes = bytes + VALUES(bytes), hits = hits + VALUES(hits)',
                [$sid, $day, $agg['bytes'], $agg['hits']]
            );
        }
    }

    traffic_save_offsets($offsets);
    setting_set('traffic_collect_at', (string)time());

    try {
        traffic_check_quota();
    } catch (Throwable $e) {
        // 配额检查失败不影响采集
    }

    return ['ok' => true, 'lines' => $totalLines, 'sites' => $touched];
}

/**
 * 解析一行 combined 日志：返回发生日期与响应字节数
 */
function traffic_parse_line(string $line): ?array
{
    if (!preg_match('/^\S+ \S+ \S+ \[([^\]]+)\] "[^"]*" (\d{3}) (\d+|-)/', $line, $m)) {
        return null;
    }
    $dt = DateTime::createFromFormat('d/M/Y:H:i:s O', $m[1]);
    if ($dt === false) {
        return null;
    }
    $bytes = $m[3] === '-' ? 0 : (int)$m[3];
    return ['day' => $dt->format('Y-m-d'), 'bytes' => $bytes, 'status' => (int)$m[2]];
}

function traffic_today_bytes(int $siteId): int
{
    $row = fetch_one(
        'SELECT bytes FROM ' . t('traffic') . ' WHERE site_id = ? AND day = CURDATE()',
        [$siteId]
    );
    return (int)($row['bytes'] ?? 0);
}

function traffic_month_bytes(int $siteId): int
{
    $row = fetch_one(
        'SELECT COALESCE(SUM(bytes), 0) AS bytes FROM ' . t('traffic') . '
         WHERE site_id = ? AND day >= DATE_FORMAT(CURDATE(), "%Y-%m-01")',
        [$siteId]
    );
    return (int)($row['bytes'] ?? 0);
}

/**
 * 近 N 天流量曲线
 */
function traffic_series(int $siteId, int $days = 30): array
{
    $rows = fetch_all(
        'SELECT day, bytes, hits FROM ' . t('traffic') . '
         WHERE site_id = ? AND day >= DATE_SUB(CURDATE(), INTERVAL ' . max(1, $days) . ' DAY)
         ORDER BY day ASC',
        [$siteId]
    );
    return array_map(fn($r) => [
        'day' => (string)$r['day'],
        'bytes' => (int)$r['bytes'],
        'hits' => (int)$r['hits'],
    ], $rows);
}

/**
 * 月配额告警：每站点每月只提醒一次
 */
function traffic_check_quota(): void
{
    $sites = fetch_all('SELECT id, name, traffic_quota_mb FROM ' . t('sites') . ' WHERE traffic_quota_mb > 0');
    $month = date('Ym');
    foreach ($sites as $site) {
        $quota = (int)$site['traffic_quota_mb'] * 1048576;
        if ($quota <= 0) {
            continue;
        }
        $used = traffic_month_bytes((int)$site['id']);
        if ($used < $quota) {
            continue;
        }
        $marker = 'traffic_alert_' . (int)$site['id'] . '_' . $month;
        if (setting($marker, '') !== '') {
            continue;
        }
        setting_set($marker, date('Y-m-d H:i:s'));
        $pct = (int)round($used / $quota * 100);
        if (function_exists('alerts_record')) {
            alerts_record(
                'traffic',
                'warning',
                '站点流量超出月配额',
                '站点「' . $site['name'] . '」本月已用 ' . bytes($used) . '，配额 ' . bytes($quota) . '（' . $pct . '%）',
                ['site' => $site['name'], 'used' => $used, 'quota' => $quota]
            );
        }
    }
}
