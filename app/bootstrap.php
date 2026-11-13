<?php
declare(strict_types=1);

/**
 * Tarsk 面板引导：配置、会话、鉴权、模板、设置、日志
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/system.php';
require_once __DIR__ . '/runtime.php';
require_once __DIR__ . '/site_vhost.php';
require_once __DIR__ . '/envconfig.php';
require_once __DIR__ . '/loganalytics.php';
require_once __DIR__ . '/traffic.php';
require_once __DIR__ . '/selfheal.php';
require_once __DIR__ . '/dbadmin.php';
require_once __DIR__ . '/filesecurity.php';
require_once __DIR__ . '/authz.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/remote.php';
require_once __DIR__ . '/projects.php';
require_once __DIR__ . '/gitdeploy.php';
require_once __DIR__ . '/sysadmin.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/plugins.php';
require_once __DIR__ . '/panel_ssl.php';
require_once __DIR__ . '/acme.php';
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/tasks.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/updater.php';

date_default_timezone_set((string)(config('app.timezone') ?: 'Asia/Shanghai'));

if (config('app.debug')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

if (PHP_SAPI !== 'cli') {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

/* --------------------------------- 基础 --------------------------------- */

function config(string $key, $default = null)
{
    $parts = explode('.', $key);
    $value = $GLOBALS['config'] ?? [];
    foreach ($parts as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function app_root(): string
{
    return dirname(__DIR__);
}

function storage_path(string $sub = ''): string
{
    $base = app_root() . '/storage';
    return $sub === '' ? $base : $base . '/' . trim($sub, '/\\');
}

function ensure_storage(): void
{
    foreach (['', 'cache', 'logs', 'ssl', 'vhost', 'www', 'backup', 'tmp', 'uploads', 'trash'] as $dir) {
        $path = storage_path($dir);
        if (!is_dir($path)) {
            @mkdir($path, 0777, true);
        }
    }
}

function is_installed(): bool
{
    return is_file(app_root() . '/install.lock') && is_file(app_root() . '/config.php');
}

/* --------------------------------- 输出 --------------------------------- */

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    $base = rtrim((string)config('app.base_url', ''), '/');
    return $base . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function current_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = rtrim((string)config('app.base_url', ''), '/');
    if ($base && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    return '/' . trim($path, '/');
}

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bytes($value, int $precision = 1): string
{
    $value = (float)$value;
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $index = 0;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }
    return round($value, $index === 0 ? 0 : $precision) . ' ' . $units[$index];
}

function percent($used, $total): float
{
    $total = (float)$total;
    if ($total <= 0) {
        return 0.0;
    }
    return min(100.0, max(0.0, (float)$used / $total * 100));
}

function ago(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    $diff = time() - strtotime($datetime);
    if ($diff < 60) {
        return '刚刚';
    }
    if ($diff < 3600) {
        return (int)floor($diff / 60) . ' 分钟前';
    }
    if ($diff < 86400) {
        return (int)floor($diff / 3600) . ' 小时前';
    }
    if ($diff < 2592000) {
        return (int)floor($diff / 86400) . ' 天前';
    }
    return date('Y-m-d', strtotime($datetime));
}

function uptime_text(int $seconds): string
{
    if ($seconds <= 0) {
        return '—';
    }
    $days = (int)floor($seconds / 86400);
    $hours = (int)floor(($seconds % 86400) / 3600);
    $minutes = (int)floor(($seconds % 3600) / 60);
    if ($days > 0) {
        return sprintf('%d 天 %d 小时', $days, $hours);
    }
    if ($hours > 0) {
        return sprintf('%d 小时 %d 分', $hours, $minutes);
    }
    return sprintf('%d 分钟', $minutes);
}

/* --------------------------------- 会话 --------------------------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['_token'] ?? '';
    if (!hash_equals(csrf_token(), (string)$token)) {
        if (preg_match('#^/(sites|store)/api(?:/|$)#', current_path())) {
            json_response(['ok' => false, 'message' => 'CSRF 校验失败，请重新获取 token'], 419);
        }
        http_response_code(419);
        exit('页面已过期，请刷新后重试。');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_messages(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

/* --------------------------------- 鉴权 --------------------------------- */

function current_user(bool $fresh = false): ?array
{
    static $user = null;
    static $loaded = false;
    if ($fresh) {
        $loaded = false;
        $user = null;
    }
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $row = fetch_one('SELECT * FROM ' . t('users') . ' WHERE id = ?', [$_SESSION['uid']]);
    if (!$row) {
        unset($_SESSION['uid']);
        return null;
    }
    $user = $row;
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        flash('error', '请先登录');
        redirect('/login');
    }
    return $user;
}

function attempt_login(string $username, string $password, string $ip): bool
{
    $row = fetch_one('SELECT * FROM ' . t('users') . ' WHERE username = ?', [$username]);
    if (!$row || !password_verify($password, $row['password_hash'])) {
        log_action('登录失败', '账号 ' . $username, 'warning', null, $username);
        return false;
    }
    if (($row['status'] ?? 'enabled') === 'disabled') {
        log_action('登录被拒', '账号已停用：' . $username, 'warning', null, $username);
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$row['id'];
    execute(
        'UPDATE ' . t('users') . ' SET last_login_at = ?, last_ip = ? WHERE id = ?',
        [now(), $ip, $row['id']]
    );
    log_action('登录成功', '欢迎回来，' . $row['username'], 'info', (int)$row['id'], $row['username']);
    return true;
}

/* --------------------------------- 设置 --------------------------------- */

function settings_all(bool $fresh = false): array
{
    static $cache = null;
    if ($cache === null || $fresh) {
        $cache = [];
        foreach (fetch_all('SELECT skey, svalue FROM ' . t('settings')) as $row) {
            $cache[$row['skey']] = $row['svalue'];
        }
    }
    return $cache;
}

function setting(string $key, $default = null)
{
    $all = settings_all();
    $value = $all[$key] ?? null;
    return ($value === null || $value === '') ? $default : $value;
}

function setting_set(string $key, $value): void
{
    execute(
        'INSERT INTO ' . t('settings') . ' (skey, svalue, updated_at) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), updated_at = VALUES(updated_at)',
        [$key, (string)$value, now()]
    );
    settings_all(true);
}

/* --------------------------------- 日志 --------------------------------- */

function log_action(string $action, string $detail = '', string $level = 'info', ?int $userId = null, string $username = ''): void
{
    $user = current_user();
    execute(
        'INSERT INTO ' . t('logs') . ' (user_id, username, action, detail, ip, level, created_at) VALUES (?,?,?,?,?,?,?)',
        [
            $userId ?? ($user['id'] ?? null),
            $username !== '' ? $username : ($user['username'] ?? ''),
            mb_substr($action, 0, 120),
            mb_substr($detail, 0, 255),
            client_ip(),
            $level,
            now(),
        ]
    );
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
}

/* --------------------------------- 监控采样 --------------------------------- */

/**
 * 每 30 秒记录一次监控采样，避免轮询写爆表
 */
function record_metrics_sample(array $metrics): void
{
    $last = fetch_one('SELECT created_at FROM ' . t('monitor') . ' ORDER BY id DESC LIMIT 1');
    if ($last && time() - strtotime($last['created_at']) < 30) {
        return;
    }
    execute(
        'INSERT INTO ' . t('monitor') . '
         (cpu, load1, mem_used, mem_total, disk_used, disk_total, net_rx, net_tx, created_at)
         VALUES (?,?,?,?,?,?,?,?,?)',
        [
            $metrics['cpu'] ?? 0,
            $metrics['load1'] ?? 0,
            $metrics['mem_used'] ?? 0,
            $metrics['mem_total'] ?? 0,
            $metrics['disk_used'] ?? 0,
            $metrics['disk_total'] ?? 0,
            $metrics['net_rx'] ?? 0,
            $metrics['net_tx'] ?? 0,
            now(),
        ]
    );

    // 采样后检查资源阈值（带冷却时间，未启用通知渠道时直接跳过）
    try {
        alert_check_resources($metrics);
    } catch (Throwable $e) {
        // 告警失败不影响监控写入
    }

    // 顺带把原始采样汇总成小时均值（内部有 5 分钟节流），供 7/30 天趋势使用
    try {
        rollup_metrics();
    } catch (Throwable $e) {
        // 聚合失败不影响主流程
    }
}

/* --------------------------------- 路径 --------------------------------- */

function sites_root(): string
{
    $configured = (string)setting('sites_root', '');
    if ($configured !== '') {
        return rtrim(str_replace('\\', '/', $configured), '/');
    }
    if (PHP_OS_FAMILY === 'Windows' && is_dir('D:/wwwroot')) {
        return 'D:/wwwroot';
    }
    return storage_path('www');
}

function backup_path(): string
{
    $configured = (string)setting('backup_path', '');
    if ($configured !== '') {
        return rtrim(str_replace('\\', '/', $configured), '/');
    }
    if (PHP_OS_FAMILY === 'Windows' && is_dir('D:/backup')) {
        return 'D:/backup';
    }
    return storage_path('backup');
}

/* --------------------------------- 文件工具 --------------------------------- */

function dir_size(string $path): int
{
    if (!is_dir($path)) {
        return is_file($path) ? (int)@filesize($path) : 0;
    }
    $total = 0;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $total += (int)$file->getSize();
            }
        }
    } catch (Throwable $e) {
        return $total;
    }
    return $total;
}

function delete_path(string $path): bool
{
    if (is_file($path) || is_link($path)) {
        return @unlink($path);
    }
    if (!is_dir($path)) {
        return false;
    }
    $items = @scandir($path) ?: [];
    $ok = true;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $ok = delete_path($path . '/' . $item) && $ok;
    }
    return @rmdir($path) && $ok;
}

function file_permission_text(string $path): string
{
    $perms = @fileperms($path);
    if ($perms === false) {
        return '—';
    }
    return substr(sprintf('%o', $perms), -4);
}

/* --------------------------------- 图标 --------------------------------- */

function icon(string $name, int $size = 16): string
{
    static $paths = [
        'grid' => 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z',
        'globe' => 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zM2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z',
        'folder' => 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
        'database' => 'M12 3c4.4 0 8 1.3 8 3s-3.6 3-8 3-8-1.3-8-3 3.6-3 8-3zM4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6',
        'activity' => 'M22 12h-4l-3 9L9 3l-3 9H2',
        'clock' => 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zM12 6v6l4 2',
        'shield' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z',
        'box' => 'M21 8l-9-5-9 5v8l9 5 9-5v-8zM3 8l9 5 9-5M12 13v9',
        'lock' => 'M5 11h14v10H5zM7 11V7a5 5 0 0 1 10 0v4',
        'bag' => 'M6 2l-2 5v13a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7l-2-5zM4 7h16',
        'list' => 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
        'settings' => 'M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM19.4 15a1.7 1.7 0 0 0 .33 1.88l.03.03a2 2 0 1 1-2.83 2.83l-.03-.03a1.7 1.7 0 0 0-1.88-.33 1.7 1.7 0 0 0-1 1.55V21a2 2 0 1 1-4 0v-.06a1.7 1.7 0 0 0-1.1-1.55 1.7 1.7 0 0 0-1.88.33l-.03.03a2 2 0 1 1-2.83-2.83l.03-.03A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.55-1H3a2 2 0 1 1 0-4h.06A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.33-1.88l-.03-.03a2 2 0 1 1 2.83-2.83l.03.03A1.7 1.7 0 0 0 9 4.6 1.7 1.7 0 0 0 10 3.05V3a2 2 0 1 1 4 0v.06a1.7 1.7 0 0 0 1 1.55A1.7 1.7 0 0 0 16.88 4.6l.03-.03a2 2 0 1 1 2.83 2.83l-.03.03A1.7 1.7 0 0 0 19.4 9c.14.61.67 1.05 1.3 1.05H21a2 2 0 1 1 0 4h-.06a1.7 1.7 0 0 0-1.54 1z',
        'logout' => 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
        'plus' => 'M12 5v14M5 12h14',
        'refresh' => 'M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15',
        'search' => 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.3-4.3',
        'trash' => 'M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6',
        'edit' => 'M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z',
        'upload' => 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12',
        'download' => 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3',
        'play' => 'M5 3l14 9-14 9V3z',
        'stop' => 'M7 7h10v10H7z',
        'cpu' => 'M4 4h16v16H4zM9 9h6v6H9zM9 1v3M15 1v3M9 20v3M15 20v3M1 9h3M1 15h3M20 9h3M20 15h3',
        'memory' => 'M2 7h20v10H2zM6 7V4M10 7V4M14 7V4M18 7V4M6 17v3M10 17v3M14 17v3M18 17v3',
        'disk' => 'M22 12H2M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11zM6 16h.01M10 16h.01',
        'network' => 'M18 20V10M12 20V4M6 20v-6',
        'check' => 'M20 6L9 17l-5-5',
        'x' => 'M18 6L6 18M6 6l12 12',
        'arrow-left' => 'M19 12H5M12 19l-7-7 7-7',
        'chevron-right' => 'M9 18l6-6-6-6',
        'external' => 'M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3',
        'server' => 'M2 3h20v7H2zM2 14h20v7H2zM6 6.5h.01M6 17.5h.01',
        'user' => 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8z',
        'zap' => 'M13 2L3 14h9l-1 8 10-12h-9l1-8z',
        'alert' => 'M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0zM12 9v4M12 17h.01',
        'copy' => 'M9 9h11v11H9zM5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1',
        'eye' => 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8zM12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z',
        'terminal' => 'M4 17l6-6-6-6M12 19h8',
        'save' => 'M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2zM17 21v-8H7v8M7 3v5h8',
        'sun' => 'M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10zM12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42',
        'moon' => 'M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 9.79 9.79z',
        'menu' => 'M3 6h18M3 12h18M3 18h18',
    ];
    $path = $paths[$name] ?? $paths['grid'];
    return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<path d="' . $path . '"/></svg>';
}

/* --------------------------------- 模板 --------------------------------- */

function render(string $view, array $data = [], string $layout = 'app'): void
{
    extract($data, EXTR_SKIP);
    $viewFile = app_root() . '/app/views/' . $view . '.php';
    if (!is_file($viewFile)) {
        http_response_code(500);
        exit('模板不存在: ' . e($view));
    }
    ob_start();
    require $viewFile;
    $content = ob_get_clean();
    if ($layout === 'none') {
        echo $content;
        return;
    }
    require app_root() . '/app/views/layouts/' . $layout . '.php';
}

ensure_storage();

/* --------------------------- 自动增量迁移 --------------------------- */

try {
    if ((int)setting('schema_version', '1') < 12) {
        install_schema();
        seed_data();
        setting_set('schema_version', '12');
    }
} catch (Throwable $e) {
    // 数据库未就绪时跳过，安装向导会再次执行
}

/* --------------------------- 流量采集（5 分钟节流） --------------------------- */

try {
    if (function_exists('traffic_collect') && config('database.name', '') !== '') {
        traffic_collect();
    }
} catch (Throwable $e) {
    // 采集失败不影响页面
}
