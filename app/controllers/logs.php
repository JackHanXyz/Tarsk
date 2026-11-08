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
