<?php
declare(strict_types=1);

/**
 * 数据库管理（真实连接 MySQL）
 */
function databases_page(): void
{
    $info = mysql_server_info();
    $databases = mysql_databases();
    $records = [];
    foreach (fetch_all('SELECT * FROM ' . t('databases')) as $row) {
        $records[$row['db_name']] = $row;
    }
    foreach ($databases as &$db) {
        $db['record'] = $records[$db['name']] ?? null;
        $db['used_by'] = $db['record']['site_id'] ?? null;
    }
    unset($db);

    $selected = (string)($_GET['db'] ?? '');
    $selectedTables = $selected !== '' ? mysql_tables($selected) : [];

    $backups = [];
    foreach (glob(backup_path() . '/*.sql') ?: [] as $file) {
        $backups[] = [
            'name' => basename($file),
            'size' => (int)filesize($file),
            'mtime' => (int)filemtime($file),
        ];
    }
    usort($backups, fn($a, $b) => $b['mtime'] <=> $a['mtime']);

    render('databases', [
        'title' => '数据库',
        'active' => 'databases',
        'info' => $info,
        'databases' => $databases,
        'selected' => $selected,
        'tables' => $selectedTables,
        'backups' => array_slice($backups, 0, 20),
        'mysql_bin' => find_mysql_bin(),
    ], 'app');
}

function databases_create(): void
{
    $name = trim((string)($_POST['name'] ?? ''));
    $charset = (string)($_POST['charset'] ?? 'utf8mb4');
    $charset = in_array($charset, ['utf8mb4', 'utf8', 'gbk'], true) ? $charset : 'utf8mb4';

    $result = mysql_create_database($name, $charset);
    if ($result['ok']) {
        execute(
            'INSERT INTO ' . t('databases') . ' (db_name, db_user, db_pass, site_id, remark, created_at) VALUES (?,?,?,?,?,?)',
            [$name, '', '', null, trim((string)($_POST['remark'] ?? '')), now()]
        );
        log_action('创建数据库', $name . ' · ' . $charset);
        flash('success', $result['message']);
    } else {
        flash('error', $result['message']);
    }
    redirect('/databases');
}

function databases_drop(): void
{
    $name = (string)($_POST['name'] ?? '');
    $result = mysql_drop_database($name);
    if ($result['ok']) {
        execute('DELETE FROM ' . t('databases') . ' WHERE db_name = ?', [$name]);
        log_action('删除数据库', $name, 'danger');
        flash('success', $result['message']);
    } else {
        flash('error', $result['message']);
    }
    redirect('/databases');
}

function databases_backup(): void
{
    $name = (string)($_POST['name'] ?? '');
    $result = mysql_dump($name);
    log_action('备份数据库', $name . ' -> ' . ($result['file'] ?? ''), $result['ok'] ? 'info' : 'warning');
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/databases');
}

function databases_tables(): void
{
    $db = (string)($_GET['db'] ?? '');
    $tables = mysql_tables($db);
    json_response(['ok' => true, 'db' => $db, 'tables' => $tables]);
}
