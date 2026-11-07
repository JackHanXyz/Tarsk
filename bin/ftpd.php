<?php
declare(strict_types=1);

/**
 * FTP 守护进程入口：php bin/ftpd.php
 * 由面板以 CLI 方式拉起，常驻阻塞运行。
 */

if (PHP_SAPI !== 'cli') {
    exit('仅支持命令行运行');
}

$root = dirname(__DIR__);

if (!is_file($root . '/config.php')) {
    fwrite(STDERR, "缺少 config.php，请先完成面板安装。\n");
    exit(1);
}

$GLOBALS['config'] = require $root . '/config.php';

// 极简 config()：从 $GLOBALS['config'] 读取 a.b 形式键（不引入 bootstrap，避免 session）
if (!function_exists('config')) {
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
}

date_default_timezone_set((string)(config('app.timezone') ?: 'Asia/Shanghai'));

require $root . '/app/db.php';
require $root . '/app/ftpd.php';

$logDir = $root . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}

try {
    $settings = [];
    foreach (fetch_all('SELECT skey, svalue FROM ' . t('settings')) as $row) {
        $settings[$row['skey']] = $row['svalue'];
    }
} catch (Throwable $e) {
    fwrite(STDERR, '读取设置失败：' . $e->getMessage() . "\n");
    exit(1);
}

$settingValue = function (string $key, string $default) use ($settings): string {
    $v = $settings[$key] ?? '';
    return ($v === null || $v === '') ? $default : (string)$v;
};

$port = (int)$settingValue('ftp_port', '2121');
$pasvMin = (int)$settingValue('ftp_pasv_min', '49000');
$pasvMax = (int)$settingValue('ftp_pasv_max', '49100');
$sitesRoot = $settingValue('sites_root', '');
if ($sitesRoot === '') {
    if (PHP_OS_FAMILY === 'Windows' && is_dir('D:/wwwroot')) {
        $sitesRoot = 'D:/wwwroot';
    } else {
        $sitesRoot = $root . '/storage/www';
    }
}
if (!is_dir($sitesRoot)) {
    @mkdir($sitesRoot, 0777, true);
}

$userProvider = function (string $username): ?array {
    return fetch_one(
        'SELECT password_hash, home_dir, status FROM ' . t('ftp_users') . ' WHERE username = ?',
        [$username]
    );
};

ftpd_run([
    'port' => $port,
    'host' => '0.0.0.0',
    'pasv_min' => $pasvMin,
    'pasv_max' => $pasvMax,
    'root' => $sitesRoot,
    'log' => $logDir . '/ftpd.log',
    'user_provider' => $userProvider,
]);
