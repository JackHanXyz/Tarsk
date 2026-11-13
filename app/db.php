<?php
declare(strict_types=1);

/**
 * Tarsk 数据层：PDO 连接、表结构、初始化种子
 */

function db_prefix(): string
{
    return (string)(config('database.prefix') ?: 'tarsk_');
}

function make_pdo(?array $override = null): PDO
{
    $db = $override ?: config('database');
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $db['host'], $db['port'], $db['name'], $db['charset'] ?? 'utf8mb4'
    );
    return new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = make_pdo();
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function fetch_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function fetch_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function execute(string $sql, array $params = []): int
{
    return q($sql, $params)->rowCount();
}

function t(string $name): string
{
    return db_prefix() . $name;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/* ------------------------------ 数据表结构 ------------------------------ */

function schema_statements(): array
{
    $p = db_prefix();

    return [
        "CREATE TABLE IF NOT EXISTS {$p}alerts (
              `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
              `kind` varchar(40) NOT NULL DEFAULT 'resource',
              `level` enum('info','warning','danger') NOT NULL DEFAULT 'warning',
              `title` varchar(190) NOT NULL DEFAULT '',
              `message` text,
              `metric` varchar(40) NOT NULL DEFAULT '',
              `value` decimal(10,2) NOT NULL DEFAULT '0.00',
              `threshold` decimal(10,2) NOT NULL DEFAULT '0.00',
              `sent` tinyint(1) NOT NULL DEFAULT '0',
              `channel_result` varchar(255) NOT NULL DEFAULT '',
              `created_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_created` (`created_at`),
              KEY `idx_kind` (`kind`,`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}api_tokens (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `name` varchar(120) NOT NULL DEFAULT '',
              `token_prefix` varchar(16) NOT NULL DEFAULT '',
              `token_hash` char(64) NOT NULL,
              `scopes` varchar(120) NOT NULL DEFAULT 'read',
              `user_id` int(10) unsigned NOT NULL DEFAULT '0',
              `last_used_at` datetime DEFAULT NULL,
              `last_ip` varchar(64) NOT NULL DEFAULT '',
              `expires_at` datetime DEFAULT NULL,
              `created_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_hash` (`token_hash`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}certs (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `site_id` int(10) unsigned DEFAULT NULL,
              `domain` varchar(190) NOT NULL,
              `cert_path` varchar(255) NOT NULL,
              `key_path` varchar(255) NOT NULL,
              `issuer` varchar(190) NOT NULL DEFAULT '',
              `not_before` datetime DEFAULT NULL,
              `not_after` datetime DEFAULT NULL,
              `source` enum('self','upload','letsencrypt') NOT NULL DEFAULT 'self',
              `created_at` datetime NOT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}cron (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `name` varchar(120) NOT NULL,
              `schedule` enum('every_minute','every_5_minutes','hourly','daily','weekly','boot','custom') NOT NULL DEFAULT 'daily',
              `run_time` varchar(20) NOT NULL DEFAULT '03:00',
              `command` text NOT NULL,
              `status` enum('enabled','disabled') NOT NULL DEFAULT 'enabled',
              `last_run_at` datetime DEFAULT NULL,
              `last_result` varchar(255) NOT NULL DEFAULT '',
              `created_at` datetime NOT NULL,
              `type` varchar(32) NOT NULL DEFAULT 'command',
              `payload` text,
              `cron_expr` varchar(120) NOT NULL DEFAULT '',
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}cron_logs (
              `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
              `task_id` int(10) unsigned NOT NULL,
              `started_at` datetime NOT NULL,
              `duration_ms` int(10) unsigned NOT NULL DEFAULT '0',
              `ok` tinyint(1) NOT NULL DEFAULT '0',
              `trigger_by` varchar(20) NOT NULL DEFAULT 'schedule',
              `message` varchar(500) NOT NULL DEFAULT '',
              `output` text,
              PRIMARY KEY (`id`),
              KEY `idx_task` (`task_id`,`id`),
              KEY `idx_started` (`started_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}databases (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `db_name` varchar(120) NOT NULL,
              `db_user` varchar(120) NOT NULL DEFAULT '',
              `db_pass` varchar(120) NOT NULL DEFAULT '',
              `site_id` int(10) unsigned DEFAULT NULL,
              `remark` varchar(255) NOT NULL DEFAULT '',
              `created_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `db_name` (`db_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}firewall (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `name` varchar(120) NOT NULL,
              `direction` enum('inbound','outbound') NOT NULL DEFAULT 'inbound',
              `action` enum('allow','block') NOT NULL DEFAULT 'allow',
              `protocol` enum('tcp','udp') NOT NULL DEFAULT 'tcp',
              `port` varchar(60) NOT NULL DEFAULT '',
              `remote_ip` varchar(190) NOT NULL DEFAULT 'any',
              `status` enum('enabled','disabled') NOT NULL DEFAULT 'enabled',
              `error` varchar(255) NOT NULL DEFAULT '',
              `created_at` datetime NOT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}ftp_users (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `username` varchar(60) NOT NULL,
              `password_hash` varchar(255) NOT NULL,
              `home_dir` varchar(255) NOT NULL DEFAULT '',
              `status` enum('enabled','disabled') NOT NULL DEFAULT 'enabled',
              `created_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `username` (`username`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}login_attempts (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `ip` varchar(64) NOT NULL,
              `username` varchar(120) NOT NULL DEFAULT '',
              `attempts` int(10) unsigned NOT NULL DEFAULT '0',
              `locked_until` datetime DEFAULT NULL,
              `updated_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_ip_user` (`ip`,`username`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}logs (
              `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
              `user_id` int(10) unsigned DEFAULT NULL,
              `username` varchar(60) NOT NULL DEFAULT '',
              `action` varchar(120) NOT NULL,
              `detail` varchar(255) NOT NULL DEFAULT '',
              `ip` varchar(64) NOT NULL DEFAULT '',
              `level` enum('info','warning','danger') NOT NULL DEFAULT 'info',
              `created_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}monitor (
              `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
              `cpu` decimal(6,2) NOT NULL DEFAULT '0.00',
              `load1` decimal(6,2) NOT NULL DEFAULT '0.00',
              `mem_used` bigint(20) unsigned NOT NULL DEFAULT '0',
              `mem_total` bigint(20) unsigned NOT NULL DEFAULT '0',
              `disk_used` bigint(20) unsigned NOT NULL DEFAULT '0',
              `disk_total` bigint(20) unsigned NOT NULL DEFAULT '0',
              `net_rx` bigint(20) unsigned NOT NULL DEFAULT '0',
              `net_tx` bigint(20) unsigned NOT NULL DEFAULT '0',
              `created_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}monitor_hourly (
              `bucket` datetime NOT NULL,
              `cpu_avg` decimal(6,2) NOT NULL DEFAULT '0.00',
              `cpu_max` decimal(6,2) NOT NULL DEFAULT '0.00',
              `mem_avg` decimal(6,2) NOT NULL DEFAULT '0.00',
              `mem_max` decimal(6,2) NOT NULL DEFAULT '0.00',
              `disk_avg` decimal(6,2) NOT NULL DEFAULT '0.00',
              `load_avg` decimal(6,2) NOT NULL DEFAULT '0.00',
              `net_rx` bigint(20) unsigned NOT NULL DEFAULT '0',
              `net_tx` bigint(20) unsigned NOT NULL DEFAULT '0',
              `samples` int(10) unsigned NOT NULL DEFAULT '0',
              PRIMARY KEY (`bucket`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}traffic (
              `site_id` int(10) unsigned NOT NULL,
              `day` date NOT NULL,
              `bytes` bigint(20) unsigned NOT NULL DEFAULT '0',
              `hits` int(10) unsigned NOT NULL DEFAULT '0',
              PRIMARY KEY (`site_id`, `day`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}plugins (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `app_key` varchar(60) NOT NULL,
              `name` varchar(120) NOT NULL,
              `version` varchar(40) NOT NULL DEFAULT '',
              `enabled` tinyint(1) NOT NULL DEFAULT '0',
              `installed_at` datetime NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `app_key` (`app_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}recycle (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `name` varchar(190) NOT NULL,
              `orig_path` varchar(255) NOT NULL,
              `trash_path` varchar(255) NOT NULL,
              `is_dir` tinyint(1) NOT NULL DEFAULT '0',
              `size` bigint(20) unsigned NOT NULL DEFAULT '0',
              `deleted_at` datetime NOT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}remote_targets (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `name` varchar(120) NOT NULL DEFAULT '',
              `kind` enum('s3','oss','webdav') NOT NULL DEFAULT 's3',
              `endpoint` varchar(255) NOT NULL DEFAULT '',
              `region` varchar(60) NOT NULL DEFAULT '',
              `bucket` varchar(190) NOT NULL DEFAULT '',
              `remote_dir` varchar(190) NOT NULL DEFAULT 'tarsk',
              `access_key` varchar(190) NOT NULL DEFAULT '',
              `secret_key` varchar(255) NOT NULL DEFAULT '',
              `extra` varchar(255) NOT NULL DEFAULT '',
              `status` enum('enabled','disabled') NOT NULL DEFAULT 'enabled',
              `last_result` varchar(255) NOT NULL DEFAULT '',
              `last_at` datetime DEFAULT NULL,
              `created_at` datetime NOT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}settings (
              `skey` varchar(190) NOT NULL,
              `svalue` text NOT NULL,
              `updated_at` datetime NOT NULL,
              PRIMARY KEY (`skey`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}sites (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `name` varchar(120) NOT NULL,
              `domain` varchar(190) NOT NULL DEFAULT '',
              `path` varchar(255) NOT NULL,
              `php_version` varchar(20) NOT NULL DEFAULT '',
              `port` int(10) unsigned NOT NULL DEFAULT '80',
              `ssl_enabled` tinyint(1) NOT NULL DEFAULT '0',
              `status` enum('running','stopped') NOT NULL DEFAULT 'running',
              `remark` varchar(255) NOT NULL DEFAULT '',
              `created_at` datetime NOT NULL,
              `rewrite` varchar(40) NOT NULL DEFAULT '',
              `php_port` int(10) unsigned NOT NULL DEFAULT '0',
              `proxy_enabled` tinyint(1) NOT NULL DEFAULT '0',
              `proxy_pass` varchar(255) NOT NULL DEFAULT '',
              `domains` varchar(500) NOT NULL DEFAULT '',
              `index_files` varchar(255) NOT NULL DEFAULT 'index.html index.htm index.php',
              `force_https` tinyint(1) NOT NULL DEFAULT '0',
              `anti_leech` tinyint(1) NOT NULL DEFAULT '0',
              `open_basedir` tinyint(1) NOT NULL DEFAULT '0',
              `upload_size` varchar(20) NOT NULL DEFAULT '64m',
              `rate_limit` int(10) unsigned NOT NULL DEFAULT '0',
              `rate_burst` int(10) unsigned NOT NULL DEFAULT '0',
              `conn_limit` int(10) unsigned NOT NULL DEFAULT '0',
              `http2_enabled` tinyint(1) NOT NULL DEFAULT '0',
              `hsts_enabled` tinyint(1) NOT NULL DEFAULT '0',
              PRIMARY KEY (`id`),
              UNIQUE KEY `name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}soft (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `app_key` varchar(60) NOT NULL,
              `name` varchar(120) NOT NULL,
              `version` varchar(60) NOT NULL DEFAULT '',
              `path` varchar(255) NOT NULL DEFAULT '',
              `status` enum('missing','installed','stopped','running','installing') NOT NULL DEFAULT 'missing',
              `checked_at` datetime DEFAULT NULL,
              `port` int(10) unsigned NOT NULL DEFAULT '0',
              `pid` int(10) unsigned NOT NULL DEFAULT '0',
              `managed` tinyint(1) NOT NULL DEFAULT '0',
              `log_path` varchar(255) NOT NULL DEFAULT '',
              `updated_at` datetime DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `app_key` (`app_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}users (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `username` varchar(60) NOT NULL,
              `password_hash` varchar(255) NOT NULL,
              `role` enum('admin','operator','viewer') NOT NULL DEFAULT 'admin',
              `last_login_at` datetime DEFAULT NULL,
              `last_ip` varchar(64) NOT NULL DEFAULT '',
              `created_at` datetime NOT NULL,
              `totp_secret` varchar(64) NOT NULL DEFAULT '',
              `totp_enabled` tinyint(1) NOT NULL DEFAULT '0',
              `status` enum('enabled','disabled') NOT NULL DEFAULT 'enabled',
              PRIMARY KEY (`id`),
              UNIQUE KEY `username` (`username`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}project_apps (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            kind ENUM('node','python','java') NOT NULL DEFAULT 'node',
            path VARCHAR(255) NOT NULL DEFAULT '',
            command VARCHAR(255) NOT NULL DEFAULT '',
            port INT UNSIGNED NOT NULL DEFAULT 0,
            autostart TINYINT(1) NOT NULL DEFAULT 0,
            pid INT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('stopped','running','failed') NOT NULL DEFAULT 'stopped',
            last_log TEXT NULL,
            last_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uk_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}webhooks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            site_id INT UNSIGNED NOT NULL DEFAULT 0,
            repo VARCHAR(255) NOT NULL DEFAULT '',
            branch VARCHAR(60) NOT NULL DEFAULT 'main',
            secret VARCHAR(64) NOT NULL DEFAULT '',
            last_pull_at DATETIME NULL,
            last_result VARCHAR(255) NOT NULL DEFAULT '',
            pull_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uk_site (site_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

function install_schema(): void
{
    foreach (schema_statements() as $sql) {
        db()->exec($sql);
    }
    migrate_schema();
}

/* ---------------------------- 增量字段迁移 ---------------------------- */

function column_exists(string $table, string $column): bool
{
    $row = fetch_one(
        'SELECT COUNT(*) AS n FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [t($table), $column]
    );
    return (int)($row['n'] ?? 0) > 0;
}

function ensure_column(string $table, string $column, string $definition): void
{
    if (!column_exists($table, $column)) {
        db()->exec('ALTER TABLE ' . t($table) . ' ADD COLUMN `' . $column . '` ' . $definition);
    }
}

function migrate_schema(): void
{
    $p = db_prefix();

    // 升级场景：补齐第二阶段新增的表（CREATE IF NOT EXISTS 幂等）
    foreach (schema_statements() as $sql) {
        db()->exec($sql);
    }

    ensure_column('sites', 'rewrite', "VARCHAR(40) NOT NULL DEFAULT ''");
    ensure_column('sites', 'php_port', 'INT UNSIGNED NOT NULL DEFAULT 0');
    ensure_column('sites', 'proxy_enabled', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensure_column('sites', 'proxy_pass', "VARCHAR(255) NOT NULL DEFAULT ''");
    ensure_column('sites', 'domains', "VARCHAR(500) NOT NULL DEFAULT ''");
    ensure_column('sites', 'index_files', "VARCHAR(255) NOT NULL DEFAULT 'index.html index.htm index.php'");
    ensure_column('sites', 'force_https', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensure_column('sites', 'anti_leech', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensure_column('sites', 'open_basedir', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensure_column('cron', 'type', "VARCHAR(32) NOT NULL DEFAULT 'command'");
    ensure_column('cron', 'payload', 'TEXT NULL');
    ensure_column('users', 'totp_secret', "VARCHAR(64) NOT NULL DEFAULT ''");
    ensure_column('users', 'totp_enabled', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensure_column('soft', 'port', 'INT UNSIGNED NOT NULL DEFAULT 0');
    ensure_column('soft', 'pid', 'INT UNSIGNED NOT NULL DEFAULT 0');
    ensure_column('soft', 'managed', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensure_column('soft', 'log_path', "VARCHAR(255) NOT NULL DEFAULT ''");
    ensure_column('soft', 'updated_at', 'DATETIME NULL');

    // 2026-11 会话新增：站点限流/上传（A 档）、cron 自定义表达式（B 档）、用户状态（D 档）
    ensure_column('sites', 'upload_size', "VARCHAR(20) NOT NULL DEFAULT ''");
    ensure_column('sites', 'rate_limit', 'INT UNSIGNED NOT NULL DEFAULT 0');
    ensure_column('sites', 'rate_burst', 'INT UNSIGNED NOT NULL DEFAULT 0');
    ensure_column('sites', 'conn_limit', 'INT UNSIGNED NOT NULL DEFAULT 0');
    ensure_column('sites', 'http2_enabled', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensure_column('sites', 'hsts_enabled', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensure_column('cron', 'cron_expr', "VARCHAR(60) NOT NULL DEFAULT ''");
    ensure_column('users', 'status', "ENUM('enabled','disabled') NOT NULL DEFAULT 'enabled'");
    ensure_column('users', 'recovery_codes', 'TEXT NULL');
    ensure_column('sites', 'traffic_quota_mb', 'INT UNSIGNED NOT NULL DEFAULT 0');


    try {
        db()->exec(
            "ALTER TABLE {$p}soft MODIFY COLUMN status ENUM('missing','installed','stopped','running','installing')
             NOT NULL DEFAULT 'missing'"
        );
    } catch (Throwable $e) {
        // 已是目标结构时忽略
    }
    try {
        db()->exec(
            "ALTER TABLE {$p}certs MODIFY COLUMN source ENUM('self','upload','letsencrypt') NOT NULL DEFAULT 'self'"
        );
    } catch (Throwable $e) {
        // 已是目标结构时忽略
    }
    try {
        db()->exec(
            "ALTER TABLE {$p}cron MODIFY COLUMN schedule ENUM('every_minute','every_5_minutes','hourly','daily','weekly','boot','custom') NOT NULL DEFAULT 'daily'"
        );
    } catch (Throwable $e) {
        // 已是目标结构时忽略
    }
    try {
        db()->exec(
            "ALTER TABLE {$p}users MODIFY COLUMN role ENUM('admin','operator','viewer') NOT NULL DEFAULT 'admin'"
        );
    } catch (Throwable $e) {
        // 已是目标结构时忽略
    }
}

function seed_data(array $admin = []): void
{
    $p = db_prefix();
    if ((int)fetch_one("SELECT COUNT(*) AS n FROM {$p}users")['n'] === 0) {
        execute(
            "INSERT INTO {$p}users (username, password_hash, role, created_at) VALUES (?,?,?,?)",
            [
                $admin['username'] ?? 'admin',
                password_hash($admin['password'] ?? 'Tarsk@2026', PASSWORD_DEFAULT),
                'admin',
                now(),
            ]
        );
    }

    $defaults = [
        'panel_name' => 'Tarsk',
        'sites_root' => '',
        'backup_path' => '',
        'refresh_interval' => '3',
        'monitor_retention_hours' => '72',
        'mysql_admin_user' => '',
        'mysql_admin_pass' => '',
        'login_notice' => '',
        'nginx_port' => '8080',
        'php_port' => '9000',
        'redis_port' => '6380',
        'panel_port' => '8888',
        'recycle_enabled' => '1',
        'security_entrance' => '',
        'ftp_port' => '2121',
        'panel_ssl_enabled' => '0',
        'panel_ssl_port' => '8443',
        'backup_retention' => '7',
        'cert_renew_days' => '30',
        'panel_ip_allowlist' => '',
        'login_max_fail' => '5',
        'login_lock_minutes' => '15',
        'notify_channels' => '',
        'notify_webhook' => '',
        'smtp_host' => '',
        'smtp_port' => '465',
        'smtp_user' => '',
        'smtp_pass' => '',
        'smtp_secure' => 'ssl',
        'smtp_from' => '',
        'smtp_to' => '',
        'alert_cpu' => '90',
        'alert_mem' => '90',
        'alert_disk' => '90',
        'alert_cooldown' => '30',
        'alert_last_at' => '',
        'update_branch' => 'main',
        'update_proxy' => '',
        'update_cache' => '',
        'update_last_result' => '',
        'installed_at' => now(),
    ];
    foreach ($defaults as $key => $value) {
        execute(
            "INSERT IGNORE INTO {$p}settings (skey, svalue, updated_at) VALUES (?,?,?)",
            [$key, $value, now()]
        );
    }

    trim_logs();
}

function trim_logs(): void
{
    $p = db_prefix();
    execute("DELETE FROM {$p}logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    execute("DELETE FROM {$p}monitor WHERE created_at < DATE_SUB(NOW(), INTERVAL 72 HOUR)");
    execute("DELETE FROM {$p}monitor_hourly WHERE bucket < DATE_SUB(NOW(), INTERVAL 180 DAY)");
}

/**
 * 把原始采样（30 秒一条、只保留 72 小时）汇总成小时均值，长期保留 180 天。
 * 幂等：按小时窗口重算并覆盖，当前小时会在下一次调用时刷新。
 */
function rollup_metrics(bool $force = false): void
{
    $last = (int)setting('metrics_rollup_at', '0');
    if (!$force && time() - $last < 300) {
        return;
    }
    $p = db_prefix();
    $memPct = 'AVG(mem_used / NULLIF(mem_total, 0) * 100)';
    $memMax = 'MAX(mem_used / NULLIF(mem_total, 0) * 100)';
    $diskPct = 'AVG(disk_used / NULLIF(disk_total, 0) * 100)';
    execute(
        "INSERT INTO {$p}monitor_hourly
            (bucket, cpu_avg, cpu_max, mem_avg, mem_max, disk_avg, load_avg, net_rx, net_tx, samples)
         SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS b,
                ROUND(AVG(cpu), 2), ROUND(MAX(cpu), 2),
                ROUND({$memPct}, 2), ROUND($memMax, 2),
                ROUND({$diskPct}, 2), ROUND(AVG(load1), 2),
                MAX(net_rx), MAX(net_tx), COUNT(*)
         FROM {$p}monitor
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 72 HOUR)
         GROUP BY b
         ON DUPLICATE KEY UPDATE
            cpu_avg = VALUES(cpu_avg), cpu_max = VALUES(cpu_max),
            mem_avg = VALUES(mem_avg), mem_max = VALUES(mem_max),
            disk_avg = VALUES(disk_avg), load_avg = VALUES(load_avg),
            net_rx = VALUES(net_rx), net_tx = VALUES(net_tx), samples = VALUES(samples)"
    );
    setting_set('metrics_rollup_at', (string)time());
}

/**
 * 读监控趋势：24 小时内用原始采样（细），更长时间用小时聚合（粗）。
 */
function metrics_series(string $range = '24h'): array
{
    $range = in_array($range, ['1h', '24h', '7d', '30d'], true) ? $range : '24h';
    $series = [];

    if ($range === '1h' || $range === '24h') {
        $interval = $range === '1h' ? '1 HOUR' : '24 HOUR';
        $rows = fetch_all(
            'SELECT * FROM ' . t('monitor') . '
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ' . $interval . ') ORDER BY id ASC'
        );
        foreach ($rows as $row) {
            $series[] = [
                't' => date($range === '1h' ? 'H:i' : 'H:i', strtotime($row['created_at'])),
                'cpu' => round((float)$row['cpu'], 1),
                'mem' => round(percent((int)$row['mem_used'], (int)$row['mem_total']), 1),
                'disk' => round(percent((int)$row['disk_used'], (int)$row['disk_total']), 1),
                'rx' => (int)$row['net_rx'],
                'tx' => (int)$row['net_tx'],
            ];
        }
        return ['range' => $range, 'series' => $series, 'count' => count($series), 'unit' => '采样'];
    }

    $days = $range === '7d' ? 7 : 30;
    $rows = fetch_all(
        'SELECT * FROM ' . t('monitor_hourly') . '
         WHERE bucket >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY) ORDER BY bucket ASC'
    );
    foreach ($rows as $row) {
        $series[] = [
            't' => date($days <= 7 ? 'm-d H:00' : 'm-d', strtotime($row['bucket'])),
            'cpu' => round((float)$row['cpu_avg'], 1),
            'mem' => round((float)$row['mem_avg'], 1),
            'disk' => round((float)$row['disk_avg'], 1),
            'rx' => (int)$row['net_rx'],
            'tx' => (int)$row['net_tx'],
            'cpu_max' => round((float)$row['cpu_max'], 1),
        ];
    }
    return ['range' => $range, 'series' => $series, 'count' => count($series), 'unit' => '小时均值'];
}

/**
 * 今日 / 昨日资源摘要（用于监控页顶部对比）。
 */
function metrics_digest(): array
{
    $p = db_prefix();
    $row = fetch_one(
        "SELECT
            AVG(CASE WHEN bucket >= CURDATE() THEN cpu_avg END) AS today_cpu,
            MAX(CASE WHEN bucket >= CURDATE() THEN cpu_max END) AS today_cpu_max,
            AVG(CASE WHEN bucket >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND bucket < CURDATE() THEN cpu_avg END) AS yday_cpu,
            AVG(CASE WHEN bucket >= CURDATE() THEN mem_avg END) AS today_mem,
            AVG(CASE WHEN bucket >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND bucket < CURDATE() THEN mem_avg END) AS yday_mem
         FROM {$p}monitor_hourly
         WHERE bucket >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)"
    );
    $num = static fn($v) => $v === null ? null : round((float)$v, 1);
    return [
        'today_cpu' => $num($row['today_cpu'] ?? null),
        'today_cpu_max' => $num($row['today_cpu_max'] ?? null),
        'yday_cpu' => $num($row['yday_cpu'] ?? null),
        'today_mem' => $num($row['today_mem'] ?? null),
        'yday_mem' => $num($row['yday_mem'] ?? null),
    ];
}
