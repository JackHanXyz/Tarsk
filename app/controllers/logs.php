<?php
declare(strict_types=1);

/**
 * 操作日志：检索、筛选、导出
 */

function logs_filter(): array
{
    $keyword = trim((string)($_GET['q'] ?? ''));
    $level = (string)($_GET['level'] ?? '');
    $username = trim((string)($_GET['username'] ?? ''));
    $from = trim((string)($_GET['from'] ?? ''));
    $to = trim((string)($_GET['to'] ?? ''));

    $where = [];
    $params = [];
    if ($keyword !== '') {
        $where[] = '(action LIKE ? OR detail LIKE ? OR username LIKE ? OR ip LIKE ?)';
        $like = '%' . $keyword . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if (in_array($level, ['info', 'warning', 'danger'], true)) {
        $where[] = 'level = ?';
        $params[] = $level;
    }
    if ($username !== '') {
        $where[] = 'username LIKE ?';
        $params[] = '%' . $username . '%';
    }
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where[] = 'created_at >= ?';
        $params[] = $from . ' 00:00:00';
    }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $where[] = 'created_at <= ?';
        $params[] = $to . ' 23:59:59';
    }

    return [
        'clause' => $where ? ' WHERE ' . implode(' AND ', $where) : '',
        'params' => $params,
        'keyword' => $keyword,
        'level' => $level,
        'username' => $username,
        'from' => $from,
        'to' => $to,
    ];
}

function logs_page(): void
{
    $filter = logs_filter();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 30;

    $total = (int)(fetch_one('SELECT COUNT(*) AS n FROM ' . t('logs') . $filter['clause'], $filter['params'])['n'] ?? 0);
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $rows = fetch_all(
        'SELECT * FROM ' . t('logs') . $filter['clause'] . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
        $filter['params']
    );

    render('logs', [
        'title' => '操作日志',
        'active' => 'logs',
        'logs' => $rows,
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'keyword' => $filter['keyword'],
        'level' => $filter['level'],
        'username' => $filter['username'],
        'from' => $filter['from'],
        'to' => $filter['to'],
    ], 'app');
}

/**
 * 导出当前筛选条件下的日志为 CSV
 */
function logs_export(): void
{
    $filter = logs_filter();
    $rows = fetch_all(
        'SELECT * FROM ' . t('logs') . $filter['clause'] . ' ORDER BY id DESC LIMIT 5000',
        $filter['params']
    );

    $filename = 'tarsk-logs-' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    // BOM 让 Excel 正确识别 UTF-8
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['时间', '级别', '操作', '详情', '账号', 'IP']);
    $levelText = ['info' => '信息', 'warning' => '警告', 'danger' => '危险'];
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['created_at'],
            $levelText[$row['level']] ?? $row['level'],
            $row['action'],
            $row['detail'],
            $row['username'],
            $row['ip'],
        ]);
    }
    fclose($out);

    log_action('导出操作日志', '共 ' . count($rows) . ' 条');
    exit;
}

/**
 * 日志实时追踪：按字节偏移增量返回新增行，供前端轮询追加
 */
function logs_tail(): void
{
    $key = (string)($_GET['file'] ?? '');
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $source = $key !== '' ? log_source_by_key($key) : null;
    if ($source === null) {
        json_response(['ok' => false, 'message' => '日志文件不存在', 'lines' => [], 'offset' => 0], 404);
    }
    $path = $source['path'];
    $size = (int)@filesize($path);
    $rotated = $size < $offset;
    if ($rotated) {
        $offset = 0;
    }
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        json_response(['ok' => false, 'message' => '无法读取日志', 'lines' => [], 'offset' => $offset], 500);
    }
    fseek($fh, $offset);
    $chunk = (string)fread($fh, 65536);
    $newOffset = (int)ftell($fh);
    fclose($fh);

    $lines = [];
    if ($chunk !== '') {
        $parts = preg_split('/\r?\n/', $chunk);
        $tail = array_pop($parts);
        if ($tail !== '' && $newOffset < $size) {
            // 最后一段可能是不完整的一行，下一轮重读
            $newOffset -= strlen($tail);
        } else {
            $parts[] = $tail;
        }
        foreach ($parts as $line) {
            if ($line !== '') {
                $lines[] = $line;
            }
        }
    }

    json_response([
        'ok' => true,
        'lines' => $lines,
        'offset' => $newOffset,
        'size' => $size,
        'rotated' => $rotated,
    ]);
}

/**
 * 访问日志分析报表：PV/UV、状态码、Top URL、来源、蜘蛛、按小时趋势
 */
function logs_analyze_page(): void
{
    $sources = log_sources();
    $key = (string)($_GET['file'] ?? '');
    $source = $key !== '' ? log_source_by_key($key) : ($sources[0] ?? null);

    $range = (string)($_GET['range'] ?? 'all');
    if (!in_array($range, ['all', '1h', '24h', '7d'], true)) {
        $range = 'all';
    }
    $mb = (int)($_GET['mb'] ?? 8);
    $mb = max(1, min(64, $mb));

    $stats = null;
    if ($source !== null) {
        $stats = log_analyze($source['path'], $range, $mb * 1024 * 1024);
    }

    render('logs_analyze', [
        'title' => '访问日志分析',
        'active' => 'logs_analyze',
        'sources' => $sources,
        'source' => $source,
        'range' => $range,
        'mb' => $mb,
        'stats' => $stats,
    ], 'app');
}
