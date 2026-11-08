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
        "CREATE TABLE IF NOT EXISTS {$p}users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(60) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('admin','operator') NOT NULL DEFAULT 'admin',
            last_login_at DATETIME NULL,
            last_ip VARCHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}settings (
            skey VARCHAR(190) NOT NULL PRIMARY KEY,
            svalue TEXT NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}sites (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            domain VARCHAR(190) NOT NULL DEFAULT '',
            domains VARCHAR(500) NOT NULL DEFAULT '',
            path VARCHAR(255) NOT NULL,
            php_version VARCHAR(20) NOT NULL DEFAULT '',
            port INT UNSIGNED NOT NULL DEFAULT 80,
            rewrite VARCHAR(40) NOT NULL DEFAULT '',
            index_files VARCHAR(255) NOT NULL DEFAULT 'index.html index.htm index.php',
            force_https TINYINT(1) NOT NULL DEFAULT 0,
            anti_leech TINYINT(1) NOT NULL DEFAULT 0,
            open_basedir TINYINT(1) NOT NULL DEFAULT 0,
            proxy_enabled TINYINT(1) NOT NULL DEFAULT 0,
            proxy_pass VARCHAR(255) NOT NULL DEFAULT '',
            ssl_enabled TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('running','stopped') NOT NULL DEFAULT 'running',
            remark VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}databases (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            db_name VARCHAR(120) NOT NULL UNIQUE,
            db_user VARCHAR(120) NOT NULL DEFAULT '',
            db_pass VARCHAR(120) NOT NULL DEFAULT '',
            site_id INT UNSIGNED NULL,
            remark VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}monitor (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cpu DECIMAL(6,2) NOT NULL DEFAULT 0,
            load1 DECIMAL(6,2) NOT NULL DEFAULT 0,
            mem_used BIGINT UNSIGNED NOT NULL DEFAULT 0,
            mem_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            disk_used BIGINT UNSIGNED NOT NULL DEFAULT 0,
            disk_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
            net_rx BIGINT UNSIGNED NOT NULL DEFAULT 0,
            net_tx BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}cron (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            type VARCHAR(32) NOT NULL DEFAULT 'command',
            payload TEXT NULL,
            schedule ENUM('every_minute','every_5_minutes','hourly','daily','weekly','boot') NOT NULL DEFAULT 'daily',
            run_time VARCHAR(20) NOT NULL DEFAULT '03:00',
            command TEXT NOT NULL,
            status ENUM('enabled','disabled') NOT NULL DEFAULT 'enabled',
            last_run_at DATETIME NULL,
            last_result VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}firewall (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            direction ENUM('inbound','outbound') NOT NULL DEFAULT 'inbound',
            action ENUM('allow','block') NOT NULL DEFAULT 'allow',
            protocol ENUM('tcp','udp') NOT NULL DEFAULT 'tcp',
            port VARCHAR(60) NOT NULL DEFAULT '',
            remote_ip VARCHAR(190) NOT NULL DEFAULT 'any',
            status ENUM('enabled','disabled') NOT NULL DEFAULT 'enabled',
            error VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}certs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            site_id INT UNSIGNED NULL,
            domain VARCHAR(190) NOT NULL,
            cert_path VARCHAR(255) NOT NULL,
            key_path VARCHAR(255) NOT NULL,
            issuer VARCHAR(190) NOT NULL DEFAULT '',
            not_before DATETIME NULL,
            not_after DATETIME NULL,
            source ENUM('self','upload') NOT NULL DEFAULT 'self',
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            username VARCHAR(60) NOT NULL DEFAULT '',
            action VARCHAR(120) NOT NULL,
            detail VARCHAR(255) NOT NULL DEFAULT '',
            ip VARCHAR(64) NOT NULL DEFAULT '',
            level ENUM('info','warning','danger') NOT NULL DEFAULT 'info',
            created_at DATETIME NOT NULL,
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}soft (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            app_key VARCHAR(60) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            version VARCHAR(60) NOT NULL DEFAULT '',
            path VARCHAR(255) NOT NULL DEFAULT '',
            port INT UNSIGNED NOT NULL DEFAULT 0,
            pid INT UNSIGNED NOT NULL DEFAULT 0,
            managed TINYINT(1) NOT NULL DEFAULT 0,
            log_path VARCHAR(255) NOT NULL DEFAULT '',
            status ENUM('missing','installed','stopped','running','installing') NOT NULL DEFAULT 'missing',
            checked_at DATETIME NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}recycle (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL,
            orig_path VARCHAR(255) NOT NULL,
            trash_path VARCHAR(255) NOT NULL,
            is_dir TINYINT(1) NOT NULL DEFAULT 0,
            size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            deleted_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}ftp_users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(60) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            home_dir VARCHAR(255) NOT NULL DEFAULT '',
            status ENUM('enabled','disabled') NOT NULL DEFAULT 'enabled',
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}login_attempts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(64) NOT NULL,
            username VARCHAR(120) NOT NULL DEFAULT '',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_ip_user (ip, username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS {$p}plugins (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            app_key VARCHAR(60) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            version VARCHAR(40) NOT NULL DEFAULT '',
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            installed_at DATETIME NOT NULL
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
}
