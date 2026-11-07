<?php
declare(strict_types=1);

function logs_page(): void
{
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 30;
    $keyword = trim((string)($_GET['q'] ?? ''));
    $level = (string)($_GET['level'] ?? '');

    $where = [];
    $params = [];
    if ($keyword !== '') {
        $where[] = '(action LIKE ? OR detail LIKE ? OR username LIKE ?)';
        $like = '%' . $keyword . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if (in_array($level, ['info', 'warning', 'danger'], true)) {
        $where[] = 'level = ?';
        $params[] = $level;
    }
    $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $total = (int)(fetch_one('SELECT COUNT(*) AS n FROM ' . t('logs') . $clause, $params)['n'] ?? 0);
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $rows = fetch_all(
        'SELECT * FROM ' . t('logs') . $clause . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
        $params
    );

    render('logs', [
        'title' => '操作日志',
        'active' => 'logs',
        'logs' => $rows,
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'keyword' => $keyword,
        'level' => $level,
    ], 'app');
}
