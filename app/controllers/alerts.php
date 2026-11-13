<?php
declare(strict_types=1);

/**
 * 告警历史：资源阈值、站点巡检、计划任务等通知的落库记录与趋势
 */

function alerts_filter(): array
{
    $kind = (string)($_GET['kind'] ?? '');
    $level = (string)($_GET['level'] ?? '');
    $from = trim((string)($_GET['from'] ?? ''));
    $to = trim((string)($_GET['to'] ?? ''));

    $where = [];
    $params = [];
    if ($kind !== '' && array_key_exists($kind, alerts_kind_labels())) {
        $where[] = 'kind = ?';
        $params[] = $kind;
    } else {
        $kind = '';
    }
    if (in_array($level, ['info', 'warning', 'danger'], true)) {
        $where[] = 'level = ?';
        $params[] = $level;
    } else {
        $level = '';
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
        'kind' => $kind,
        'level' => $level,
        'from' => $from,
        'to' => $to,
    ];
}

function alerts_page(): void
{
    $filter = alerts_filter();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 30;

    $total = (int)(fetch_one('SELECT COUNT(*) AS n FROM ' . t('alerts') . $filter['clause'], $filter['params'])['n'] ?? 0);
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $rows = fetch_all(
        'SELECT * FROM ' . t('alerts') . $filter['clause'] . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
        $filter['params']
    );

    $now = time();
    $stats = [
        'last24' => (int)(fetch_one('SELECT COUNT(*) AS n FROM ' . t('alerts') . ' WHERE created_at >= ?', [date('Y-m-d H:i:s', $now - 86400)])['n'] ?? 0),
        'last7d' => (int)(fetch_one('SELECT COUNT(*) AS n FROM ' . t('alerts') . ' WHERE created_at >= ?', [date('Y-m-d H:i:s', $now - 7 * 86400)])['n'] ?? 0),
        'danger' => (int)(fetch_one('SELECT COUNT(*) AS n FROM ' . t('alerts') . ' WHERE level = ? AND created_at >= ?', ['danger', date('Y-m-d H:i:s', $now - 7 * 86400)])['n'] ?? 0),
        'total' => (int)(fetch_one('SELECT COUNT(*) AS n FROM ' . t('alerts') . '')['n'] ?? 0),
    ];

    render('alerts', [
        'title' => '告警历史',
        'active' => 'alerts',
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'stats' => $stats,
        'series' => alerts_daily_series(14),
        'kinds' => alerts_kind_labels(),
        'kind' => $filter['kind'],
        'level' => $filter['level'],
        'from' => $filter['from'],
        'to' => $filter['to'],
        'channels' => notify_channels(),
    ], 'app');
}

function alerts_delete(int $id): void
{
    execute('DELETE FROM ' . t('alerts') . ' WHERE id = ?', [$id]);
    flash('success', '告警记录已删除');
    redirect('/alerts');
}

function alerts_clear(): void
{
    $days = (int)($_POST['days'] ?? 0);
    if ($days > 0) {
        execute('DELETE FROM ' . t('alerts') . ' WHERE created_at < ?', [date('Y-m-d H:i:s', time() - $days * 86400)]);
        log_action('清理告警历史', '删除 ' . $days . ' 天前的记录', 'warning');
        flash('success', '已删除 ' . $days . ' 天前的告警记录');
    } else {
        execute('DELETE FROM ' . t('alerts') . '');
        log_action('清空告警历史', '全部记录', 'danger');
        flash('success', '告警历史已清空');
    }
    redirect('/alerts');
}
