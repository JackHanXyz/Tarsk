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

/* --------------------------- 用户与权限 --------------------------- */

function databases_users_page(): void
{
    $userList = mysql_user_list();
    $databases = array_map(static fn($db) => $db['name'], mysql_databases());

    render('databases_users', [
        'title' => '数据库用户',
        'active' => 'databases',
        'users' => $userList['users'],
        'list_error' => $userList['error'],
        'can_manage' => mysql_can_manage_globally(),
        'grants' => mysql_admin_grants(),
        'databases' => $databases,
        'version' => mysql_version_number(),
        'connected' => mysql_connection() !== null,
    ], 'app');
}

function databases_users_create(): void
{
    $user = trim((string)($_POST['user'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $remote = !empty($_POST['remote']);
    $host = $remote ? '%' : 'localhost';
    $privileges = (string)($_POST['privileges'] ?? 'ALL PRIVILEGES');
    $dbs = $_POST['databases'] ?? [];
    $dbs = is_array($dbs) ? array_values(array_filter(array_map('strval', $dbs))) : [];

    $result = mysql_user_create($user, $password, $host, $dbs, $privileges);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/databases/users');
}

function databases_users_drop(): void
{
    $user = (string)($_POST['user'] ?? '');
    $host = (string)($_POST['host'] ?? 'localhost');
    $result = mysql_user_drop($user, $host);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/databases/users');
}

function databases_users_password(): void
{
    $user = (string)($_POST['user'] ?? '');
    $host = (string)($_POST['host'] ?? 'localhost');
    $password = (string)($_POST['password'] ?? '');
    $result = mysql_user_password($user, $host, $password);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/databases/users');
}

function databases_users_remote(): void
{
    $user = (string)($_POST['user'] ?? '');
    $host = (string)($_POST['host'] ?? 'localhost');
    $remote = !empty($_POST['remote']);
    $result = mysql_user_remote($user, $host, $remote);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/databases/users');
}

/* ----------------------------- SQL 导入 ----------------------------- */

function databases_import(): void
{
    $db = (string)($_POST['db'] ?? '');
    if ($db === '') {
        flash('error', '请选择目标数据库');
        redirect('/databases');
    }
    $file = $_FILES['sql'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('error', '没有收到 SQL 文件（可能超过 PHP 上传上限 ' . ini_get('upload_max_filesize') . '）');
        redirect('/databases?db=' . urlencode($db));
    }
    $name = (string)($file['name'] ?? 'import.sql');
    if (!preg_match('/\.(sql|txt)$/i', $name)) {
        flash('error', '只支持 .sql / .txt 文件');
        redirect('/databases?db=' . urlencode($db));
    }
    $tmp = storage_path('tmp/import_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.sql');
    @mkdir(dirname($tmp), 0777, true);
    if (!move_uploaded_file((string)$file['tmp_name'], $tmp)) {
        flash('error', '暂存上传文件失败');
        redirect('/databases?db=' . urlencode($db));
    }

    $result = mysql_import_file($db, $tmp);
    @unlink($tmp);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/databases?db=' . urlencode($db));
}

/* ---------------------------- 慢查询报表 ---------------------------- */

function databases_slow(): void
{
    $mb = (int)($_GET['mb'] ?? 4);
    $mb = max(1, min(64, $mb));
    $limit = (int)($_GET['limit'] ?? 20);
    $limit = max(5, min(100, $limit));

    $report = mysql_slow_aggregate($mb * 1024 * 1024, $limit);
    $vars = mysql_env_variables();

    render('databases_slow', [
        'title' => '慢查询报表',
        'active' => 'databases',
        'report' => $report,
        'mb' => $mb,
        'limit' => $limit,
        'slow_on' => strtolower((string)($vars['slow_query_log'] ?? 'off')) === 'on',
        'long_query_time' => (string)($vars['long_query_time'] ?? ''),
        'log_output' => (string)($vars['log_output'] ?? ''),
    ], 'app');
}

function databases_slow_save(): void
{
    $enable = !empty($_POST['enable']);
    $seconds = (string)($_POST['long_query_time'] ?? '2');
    $result = mysql_env_set_variable('slow_query_log', $enable ? 'On' : 'Off');
    if (!$result['ok']) {
        flash('error', $result['message']);
        redirect('/databases/slow');
    }
    $timeResult = mysql_env_set_variable('long_query_time', $seconds);
    $messages = [$result['message'], $timeResult['message']];
    flash($timeResult['ok'] ? 'success' : 'warning', implode('；', $messages));
    redirect('/databases/slow');
}

/* ----------------------------- 复制状态 ----------------------------- */

function databases_replication(): void
{
    render('databases_replication', [
        'title' => '主从与同步',
        'active' => 'databases',
        'status' => mysql_replication_status(),
        'version' => mysql_version_number(),
        'host' => (string)config('database.host', '127.0.0.1'),
        'port' => (string)config('database.port', '3306'),
    ], 'app');
}
