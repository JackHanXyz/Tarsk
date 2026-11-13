<?php
declare(strict_types=1);

/**
 * 数据库进阶：用户与权限、远程访问、SQL 导入、慢查询报表、复制状态
 * 全部通过管理账号（mysql_connection）执行，不依赖面板自身的库账号。
 */

function mysql_version_number(): string
{
    $pdo = mysql_connection();
    if (!$pdo) {
        return '';
    }
    try {
        $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    } catch (Throwable $e) {
        return '';
    }
    if (preg_match('/^(\d+\.\d+\.\d+)/', $version, $m)) {
        return $m[1];
    }
    return '';
}

function mysql_version_is_at_least(string $version): bool
{
    $current = mysql_version_number();
    return $current !== '' && version_compare($current, $version, '>=');
}

function mysql_identifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function mysql_quote_account(string $user, string $host): string
{
    return "'" . str_replace("'", "''", $user) . "'@'" . str_replace("'", "''", $host) . "'";
}

/* ---------------------------- 用户与权限 ---------------------------- */

/**
 * 当前管理账号的授权（用于提示"权限不足"）
 */
function mysql_admin_grants(): array
{
    $pdo = mysql_connection();
    if (!$pdo) {
        return [];
    }
    try {
        return array_map('strval', $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * 是否具备全局管理能力（创建用户 / 读 mysql.user / SET GLOBAL）
 */
function mysql_can_manage_globally(): bool
{
    foreach (mysql_admin_grants() as $grant) {
        if (preg_match('/ON\s+\*\.\*/i', $grant)
            && preg_match('/ALL PRIVILEGES|CREATE USER|GRANT OPTION|SUPER|SYSTEM_VARIABLES_ADMIN|RELOAD/i', $grant)) {
            return true;
        }
    }
    return false;
}

/**
 * @return array{ok:bool,error:string,users:array}
 */
function mysql_user_list(): array
{
    $pdo = mysql_connection();
    if (!$pdo) {
        return ['ok' => false, 'error' => '无法连接 MySQL', 'users' => []];
    }
    try {
        $raw = $pdo->query('SELECT User, Host FROM mysql.user ORDER BY User, Host')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => '当前账号无权读取 mysql.user（' . $e->getMessage() . '）', 'users' => []];
    }

    $rows = [];
    foreach ($raw as $row) {
        $user = (string)$row['User'];
        $host = (string)$row['Host'];
        if ($user === '') {
            continue;
        }
        $rows[] = [
            'user' => $user,
            'host' => $host,
            'remote' => !in_array($host, ['localhost', '127.0.0.1', '::1'], true),
            'protected' => in_array($user, ['root', 'mysql.sys', 'mysql.session', 'mysql.infoschema', 'debian-sys-maint'], true),
            'grants' => [],
            'dbs' => [],
        ];
    }
    // 授权明细（SHOW GRANTS 可能因权限不足失败，逐个兜底）
    foreach ($rows as &$row) {
        try {
            $grants = $pdo->query('SHOW GRANTS FOR ' . mysql_quote_account($row['user'], $row['host']))
                ->fetchAll(PDO::FETCH_COLUMN);
            $row['grants'] = array_map('strval', $grants);
            foreach ($row['grants'] as $grant) {
                if (preg_match('/ON\s+(`[^`]+`|\*)(\.\*)?\s+TO/i', $grant, $m)) {
                    $db = trim($m[1], '`');
                    if ($db !== '*' && $db !== '' && !in_array($db, $row['dbs'], true)) {
                        $row['dbs'][] = $db;
                    }
                }
            }
        } catch (Throwable $e) {
            // 忽略
        }
    }
    unset($row);
    return ['ok' => true, 'error' => '', 'users' => $rows];
}

/**
 * 创建用户；remote=true 时使用 % 允许远程连接
 */
function mysql_user_create(string $user, string $password, string $host, array $databases, string $privileges = 'ALL PRIVILEGES'): array
{
    $pdo = mysql_connection();
    if (!$pdo) {
        return ['ok' => false, 'message' => '无法连接 MySQL'];
    }
    if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,31}$/', $user)) {
        return ['ok' => false, 'message' => '用户名仅支持字母、数字、下划线、点与短横线（最长 32）'];
    }
    if (strlen($password) < 6) {
        return ['ok' => false, 'message' => '密码至少 6 位'];
    }
    if (!in_array($host, ['localhost', '%', '127.0.0.1'], true)) {
        $host = 'localhost';
    }
    $allowedPrivs = ['ALL PRIVILEGES', 'SELECT, INSERT, UPDATE, DELETE', 'SELECT', 'SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER'];
    if (!in_array($privileges, $allowedPrivs, true)) {
        $privileges = 'ALL PRIVILEGES';
    }
    if (!$databases) {
        return ['ok' => false, 'message' => '请至少选择一个数据库'];
    }

    $account = mysql_quote_account($user, $host);
    $passwordSql = $pdo->quote($password);
    try {
        $pdo->exec('CREATE USER ' . $account . ' IDENTIFIED BY ' . $passwordSql);
        foreach ($databases as $db) {
            $db = (string)$db;
            if ($db === '') {
                continue;
            }
            $pdo->exec('GRANT ' . $privileges . ' ON ' . mysql_identifier($db) . '.* TO ' . $account);
        }
        // 5.6/5.7 都支持；8.0 需要 CREATE USER 的默认认证即可
        try {
            $pdo->exec('FLUSH PRIVILEGES');
        } catch (Throwable $e) {
            // 忽略
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => '创建失败：' . $e->getMessage()];
    }

    log_action('创建数据库用户', $user . '@' . $host . ' → ' . implode(',', $databases), 'warning');
    return ['ok' => true, 'message' => '用户 ' . $user . '@' . $host . ' 已创建并授权'];
}

function mysql_user_drop(string $user, string $host): array
{
    $pdo = mysql_connection();
    if (!$pdo) {
        return ['ok' => false, 'message' => '无法连接 MySQL'];
    }
    if (in_array($user, ['root', 'mysql.sys', 'mysql.session', 'mysql.infoschema'], true)) {
        return ['ok' => false, 'message' => '内置账号不允许删除'];
    }
    try {
        $pdo->exec('DROP USER ' . mysql_quote_account($user, $host));
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => '删除失败：' . $e->getMessage()];
    }
    log_action('删除数据库用户', $user . '@' . $host, 'danger');
    return ['ok' => true, 'message' => '用户 ' . $user . '@' . $host . ' 已删除'];
}

function mysql_user_password(string $user, string $host, string $password): array
{
    $pdo = mysql_connection();
    if (!$pdo) {
        return ['ok' => false, 'message' => '无法连接 MySQL'];
    }
    if (strlen($password) < 6) {
        return ['ok' => false, 'message' => '密码至少 6 位'];
    }
    $account = mysql_quote_account($user, $host);
    $passwordSql = $pdo->quote($password);
    try {
        if (mysql_version_is_at_least('5.7.0')) {
            $pdo->exec('ALTER USER ' . $account . ' IDENTIFIED BY ' . $passwordSql);
        } else {
            $pdo->exec('SET PASSWORD FOR ' . $account . ' = PASSWORD(' . $passwordSql . ')');
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => '修改失败：' . $e->getMessage()];
    }
    log_action('重置数据库密码', $user . '@' . $host, 'warning');
    return ['ok' => true, 'message' => $user . '@' . $host . ' 密码已更新'];
}

/**
 * 远程访问开关：通过 RENAME USER 在 localhost 与 % 之间切换，授权随账号一起迁移
 */
function mysql_user_remote(string $user, string $host, bool $remote): array
{
    $pdo = mysql_connection();
    if (!$pdo) {
        return ['ok' => false, 'message' => '无法连接 MySQL'];
    }
    if ($user === 'root') {
        return ['ok' => false, 'message' => '内置账号不允许切换远程访问'];
    }
    $target = $remote ? '%' : 'localhost';
    $already = $remote
        ? $host === '%'
        : in_array($host, ['localhost', '127.0.0.1'], true);
    if ($already) {
        return ['ok' => true, 'message' => $user . ' 已经是' . ($remote ? '远程可访问' : '仅本机访问')];
    }
    try {
        $pdo->exec('RENAME USER ' . mysql_quote_account($user, $host) . ' TO ' . mysql_quote_account($user, $target));
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => '切换失败：' . $e->getMessage()];
    }
    log_action('切换数据库用户远程访问', $user . '@' . $host . ' → ' . $target, 'warning');
    return ['ok' => true, 'message' => $user . ' 现在' . ($remote ? '允许远程访问（%）' : '仅本机访问（localhost）')];
}

/* ------------------------------ SQL 导入 ------------------------------ */

/**
 * 导入 SQL 文件：优先用 mysql 客户端（支持 DELIMITER / 大文件），否则回落逐语句执行
 */
function mysql_import_file(string $database, string $file): array
{
    if (!is_file($file)) {
        return ['ok' => false, 'message' => '文件不存在：' . $file];
    }
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $database)) {
        return ['ok' => false, 'message' => '数据库名称不合法'];
    }
    $size = (int)filesize($file);
    $bin = find_mysql_bin();
    $client = PHP_OS_FAMILY === 'Windows' ? $bin . '/mysql.exe' : 'mysql';
    $hasClient = PHP_OS_FAMILY === 'Windows' ? is_file($client) : command_exists('mysql') !== null;

    if ($hasClient) {
        $db = config('database');
        $creds = mysql_admin_credentials();
        $ini = storage_path('tmp/mysql_' . bin2hex(random_bytes(4)) . '.cnf');
        @mkdir(dirname($ini), 0777, true);
        file_put_contents($ini, "[client]\nhost=\"" . $db['host'] . "\"\nport=" . $db['port']
            . "\nuser=\"" . $creds['user'] . "\"\npassword=\"" . str_replace('"', '', $creds['pass']) . "\"\n");
        $command = sprintf(
            '%s --defaults-extra-file=%s --default-character-set=utf8mb4 %s < %s 2>&1',
            escapeshellarg($client),
            escapeshellarg($ini),
            escapeshellarg($database),
            escapeshellarg($file)
        );
        $result = run_cmd($command, 300);
        @unlink($ini);
        $output = trim(($result['output'] ?? '') . "\n" . ($result['error'] ?? ''));
        if ($result['code'] === 0 && stripos($output, 'ERROR') === false) {
            log_action('导入 SQL', $database . ' ← ' . basename($file) . '（' . bytes($size) . '）', 'warning');
            return ['ok' => true, 'message' => '导入完成（mysql 客户端）：' . basename($file)];
        }
        return ['ok' => false, 'message' => '导入失败：' . mb_substr($output !== '' ? $output : '未知错误', 0, 300)];
    }

    // 回落：PDO 逐语句
    $content = (string)@file_get_contents($file);
    if (stripos($content, 'DELIMITER') !== false) {
        return ['ok' => false, 'message' => '文件含 DELIMITER（存储过程），需要 mysql 客户端才能导入'];
    }
    $statements = db_split_sql($content);
    $pdo = mysql_connection();
    if (!$pdo) {
        return ['ok' => false, 'message' => '无法连接 MySQL'];
    }
    try {
        $pdo->exec('USE ' . mysql_identifier($database));
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => '切换数据库失败：' . $e->getMessage()];
    }
    $done = 0;
    foreach ($statements as $sql) {
        $sql = trim($sql);
        if ($sql === '' || $sql === ';') {
            continue;
        }
        try {
            $pdo->exec($sql);
            $done++;
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => '第 ' . ($done + 1) . ' 条语句失败：' . mb_substr($e->getMessage(), 0, 200)];
        }
    }
    log_action('导入 SQL', $database . ' ← ' . basename($file) . '（' . $done . ' 条语句）', 'warning');
    return ['ok' => true, 'message' => '导入完成（PDO）：' . $done . ' 条语句'];
}

/**
 * 按分号拆分 SQL，忽略字符串与注释里的分号
 */
function db_split_sql(string $sql): array
{
    $statements = [];
    $buffer = '';
    $len = strlen($sql);
    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $inLineComment = false;
    $inBlockComment = false;

    for ($i = 0; $i < $len; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($inLineComment) {
            $buffer .= $char;
            if ($char === "\n") {
                $inLineComment = false;
            }
            continue;
        }
        if ($inBlockComment) {
            $buffer .= $char;
            if ($char === '*' && $next === '/') {
                $buffer .= $next;
                $i++;
                $inBlockComment = false;
            }
            continue;
        }
        if ($inSingle) {
            $buffer .= $char;
            if ($char === '\\') {
                $buffer .= $next;
                $i++;
            } elseif ($char === "'") {
                $inSingle = false;
            }
            continue;
        }
        if ($inDouble) {
            $buffer .= $char;
            if ($char === '\\') {
                $buffer .= $next;
                $i++;
            } elseif ($char === '"') {
                $inDouble = false;
            }
            continue;
        }
        if ($inBacktick) {
            $buffer .= $char;
            if ($char === '`') {
                $inBacktick = false;
            }
            continue;
        }

        if ($char === '-' && $next === '-') {
            $inLineComment = true;
            $buffer .= '--';
            $i++;
            continue;
        }
        if ($char === '#') {
            $inLineComment = true;
            $buffer .= $char;
            continue;
        }
        if ($char === '/' && $next === '*') {
            $inBlockComment = true;
            $buffer .= '/*';
            $i++;
            continue;
        }
        if ($char === "'") {
            $inSingle = true;
            $buffer .= $char;
            continue;
        }
        if ($char === '"') {
            $inDouble = true;
            $buffer .= $char;
            continue;
        }
        if ($char === '`') {
            $inBacktick = true;
            $buffer .= $char;
            continue;
        }
        if ($char === ';') {
            $statements[] = $buffer;
            $buffer = '';
            continue;
        }
        $buffer .= $char;
    }
    if (trim($buffer) !== '') {
        $statements[] = $buffer;
    }
    return $statements;
}

/* ---------------------------- 慢查询报表 ---------------------------- */

function mysql_slow_log_file(): string
{
    $pdo = mysql_connection();
    if (!$pdo) {
        return '';
    }
    try {
        $file = (string)$pdo->query("SHOW VARIABLES LIKE 'slow_query_log_file'")->fetch(PDO::FETCH_ASSOC)['Value'];
        $datadir = (string)$pdo->query("SHOW VARIABLES LIKE 'datadir'")->fetch(PDO::FETCH_ASSOC)['Value'];
    } catch (Throwable $e) {
        return '';
    }
    if ($file === '') {
        return '';
    }
    $file = str_replace('\\', '/', $file);
    if (!preg_match('#^[A-Za-z]:/#', $file) && strpos($file, '/') !== 0) {
        $file = rtrim(str_replace('\\', '/', $datadir), '/') . '/' . $file;
    }
    return $file;
}

/**
 * 解析慢查询日志（mysql 5.x/8.x 通用：# Query_time / SET timestamp / SQL 本体）
 */
function mysql_slow_aggregate(int $maxBytes = 4194304, int $limit = 20): array
{
    $path = mysql_slow_log_file();
    $result = [
        'ok' => false,
        'message' => '',
        'path' => $path,
        'exists' => $path !== '' && is_file($path),
        'total' => 0,
        'groups' => [],
        'bytes' => 0,
        'size' => ($path !== '' && is_file($path)) ? (int)@filesize($path) : 0,
    ];
    if ($path === '') {
        $result['message'] = '未能获取 slow_query_log_file 路径';
        return $result;
    }
    if (!is_file($path)) {
        $result['message'] = '慢查询日志文件不存在：' . $path . '（需开启 slow_query_log 才会生成）';
        return $result;
    }

    $size = (int)@filesize($path);
    $handle = @fopen($path, 'rb');
    if (!$handle) {
        $result['message'] = '无法读取慢查询日志（权限不足）';
        return $result;
    }
    $read = min($size, $maxBytes);
    if ($read < $size) {
        fseek($handle, -$read, SEEK_END);
    }
    $blob = (string)fread($handle, $read);
    fclose($handle);
    $result['bytes'] = $read;

    $lines = preg_split('/\r?\n/', $blob) ?: [];
    if ($read < $size && $lines) {
        array_shift($lines); // 丢弃可能截断的首行
    }

    $entries = [];
    $current = null;
    foreach ($lines as $line) {
        if (preg_match('/^#\s*Query_time:\s*([0-9.]+)\s+Lock_time:\s*([0-9.]+)\s+Rows_sent:\s*(\d+)\s+Rows_examined:\s*(\d+)/', $line, $m)) {
            if ($current !== null) {
                $entries[] = $current;
            }
            $current = [
                'query_time' => (float)$m[1],
                'lock_time' => (float)$m[2],
                'rows_sent' => (int)$m[3],
                'rows_examined' => (int)$m[4],
                'sql' => '',
                'time' => '',
            ];
            continue;
        }
        if ($current === null) {
            continue;
        }
        if (preg_match('/^#\s*Time:\s*(.+)$/', $line, $m)) {
            $current['time'] = trim($m[1]);
            continue;
        }
        if ($line === '' || $line[0] === '#' || stripos($line, 'SET timestamp=') === 0) {
            continue;
        }
        $current['sql'] .= ($current['sql'] === '' ? '' : ' ') . $line;
        if (mb_strlen($current['sql']) > 8000) {
            $current['sql'] = mb_substr($current['sql'], 0, 8000);
        }
    }
    if ($current !== null) {
        $entries[] = $current;
    }

    $result['total'] = count($entries);
    $groups = [];
    foreach ($entries as $entry) {
        $sql = trim((string)$entry['sql']);
        if ($sql === '') {
            continue;
        }
        $template = mysql_sql_template($sql);
        $hash = md5($template);
        if (!isset($groups[$hash])) {
            $groups[$hash] = [
                'template' => $template,
                'sample' => mb_substr($sql, 0, 400),
                'count' => 0,
                'total_time' => 0.0,
                'max_time' => 0.0,
                'rows_examined' => 0,
                'last_time' => '',
            ];
        }
        $groups[$hash]['count']++;
        $groups[$hash]['total_time'] += (float)$entry['query_time'];
        $groups[$hash]['max_time'] = max($groups[$hash]['max_time'], (float)$entry['query_time']);
        $groups[$hash]['rows_examined'] += (int)$entry['rows_examined'];
        if ($entry['time'] !== '') {
            $groups[$hash]['last_time'] = (string)$entry['time'];
        }
    }

    $list = array_values($groups);
    usort($list, static fn($a, $b) => $b['total_time'] <=> $a['total_time']);
    foreach ($list as &$group) {
        $group['avg_time'] = $group['count'] > 0 ? $group['total_time'] / $group['count'] : 0;
        $group['avg_rows'] = $group['count'] > 0 ? (int)round($group['rows_examined'] / $group['count']) : 0;
        $group['total_time'] = round($group['total_time'], 3);
        $group['max_time'] = round($group['max_time'], 3);
        $group['avg_time'] = round($group['avg_time'], 3);
    }
    unset($group);

    $result['ok'] = true;
    $result['groups'] = array_slice($list, 0, $limit);
    $result['group_count'] = count($list);
    return $result;
}

/**
 * SQL 归一化成模板：字面量替换为 ?，便于同类语句聚合
 */
function mysql_sql_template(string $sql): string
{
    $sql = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/", '?', $sql) ?? $sql;
    $sql = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '?', $sql) ?? $sql;
    $sql = preg_replace('/\b\d+(\.\d+)?\b/', '?', $sql) ?? $sql;
    $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;
    // 统一小写，避免同一语句因大小写差异被拆成两组
    return trim(mb_strtolower(mb_substr($sql, 0, 600)));
}

/* ---------------------------- 复制状态 ---------------------------- */

function mysql_replication_status(): array
{
    $pdo = mysql_connection();
    $out = [
        'ok' => false,
        'message' => '',
        'master' => [],
        'slave' => [],
        'binlog' => [],
        'server_id' => '',
        'log_bin' => '',
        'gtid' => '',
    ];
    if (!$pdo) {
        $out['message'] = '无法连接 MySQL';
        return $out;
    }
    try {
        foreach (['server_id', 'log_bin', 'log_bin_basename', 'gtid_mode', 'binlog_format', 'sync_binlog'] as $name) {
            $row = $pdo->query("SHOW VARIABLES LIKE " . $pdo->quote($name))->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $out['binlog'][$name] = (string)$row['Value'];
            }
        }
        $out['server_id'] = $out['binlog']['server_id'] ?? '';
        $out['log_bin'] = $out['binlog']['log_bin'] ?? '';
        $out['gtid'] = $out['binlog']['gtid_mode'] ?? '';
        try {
            $master = $pdo->query('SHOW MASTER STATUS')->fetch(PDO::FETCH_ASSOC);
            $out['master'] = $master ?: [];
        } catch (Throwable $e) {
            $out['master'] = [];
        }
        try {
            $slave = $pdo->query('SHOW SLAVE STATUS')->fetch(PDO::FETCH_ASSOC);
            $out['slave'] = $slave ?: [];
        } catch (Throwable $e) {
            $out['slave'] = [];
        }
        $out['ok'] = true;
    } catch (Throwable $e) {
        $out['message'] = $e->getMessage();
    }
    return $out;
}
