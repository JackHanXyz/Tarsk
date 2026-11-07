<?php
declare(strict_types=1);

/**
 * 命令行配置 + 初始化：
 * php bin/configure.php --host=127.0.0.1 --port=3306 --name=tarsk --user=root --pass=secret \
 *   --admin=admin --admin-pass=YourPassword --panel-port=8888 [--force]
 */

if (PHP_SAPI !== 'cli') {
    exit('仅支持命令行运行');
}

$root = dirname(__DIR__);
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--force' || $arg === '-f') {
        $opts['force'] = true;
    } elseif (preg_match('/^--([a-zA-Z-]+)=(.*)$/', $arg, $m)) {
        $opts[strtolower($m[1])] = $m[2];
    }
}

if (is_file($root . '/install.lock') && empty($opts['force'])) {
    fwrite(STDERR, "面板已安装（存在 install.lock）。如需重新配置，请加 --force。\n");
    exit(2);
}

$host = $opts['host'] ?? '127.0.0.1';
$port = (int)($opts['port'] ?? 3306);
$name = $opts['name'] ?? 'tarsk';
$user = $opts['user'] ?? 'tarsk';
$pass = $opts['pass'] ?? '';
$prefix = $opts['prefix'] ?? 'tarsk_';
$admin = $opts['admin'] ?? 'admin';
$adminPass = $opts['admin-pass'] ?? 'Tarsk@2026';
$panelPort = (int)($opts['panel-port'] ?? 8888);

if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
    fwrite(STDERR, "数据库名只能包含字母、数字与下划线。\n");
    exit(1);
}
if (strlen($adminPass) < 8) {
    fwrite(STDERR, "管理员密码至少 8 位。\n");
    exit(1);
}

$pdo = null;
$connectError = '';
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    $connectError = $e->getMessage();
    try {
        $server = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
            $user, $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $server->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s` DEFAULT CHARACTER SET utf8mb4', $name));
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
            $user, $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        echo "已创建数据库 {$name}\n";
    } catch (Throwable $e2) {
        if ($name !== 'test') {
            try {
                $pdo = new PDO(
                    sprintf('mysql:host=%s;port=%d;dbname=test;charset=utf8mb4', $host, $port),
                    $user, $pass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
                );
                $name = 'test';
                echo "警告：账号无建库权限，面板数据将写入 test 库（表前缀 {$prefix}）。\n";
            } catch (Throwable $e3) {
                fwrite(STDERR, "数据库连接失败：{$connectError}\n");
                exit(1);
            }
        } else {
            fwrite(STDERR, "数据库连接失败：{$connectError}\n");
            exit(1);
        }
    }
}

$config = [
    'database' => [
        'host' => $host,
        'port' => $port,
        'name' => $name,
        'user' => $user,
        'pass' => $pass,
        'charset' => 'utf8mb4',
        'prefix' => $prefix,
    ],
    'app' => [
        'name' => 'Tarsk',
        'base_url' => '',
        'debug' => false,
        'version' => '1.3.0',
        'timezone' => 'Asia/Shanghai',
    ],
    'system' => [
        'sites_root' => '',
        'backup_path' => '',
        'nginx_conf' => '',
        'mysql_bin' => '',
        'nginx_bin' => '',
    ],
];

$configPath = $root . '/config.php';
$php = "<?php\n/**\n * Tarsk 配置（安装程序生成）\n */\nreturn " . var_export($config, true) . ";\n";
if (@file_put_contents($configPath, $php) === false) {
    fwrite(STDERR, "无法写入 config.php，请检查目录权限。\n");
    exit(1);
}

$GLOBALS['config'] = $config;
require_once $root . '/app/bootstrap.php';

try {
    install_schema();
    seed_data(['username' => $admin, 'password' => $adminPass]);
    setting_set('panel_port', (string)$panelPort);
    @file_put_contents($root . '/install.lock', date('Y-m-d H:i:s'));
} catch (Throwable $e) {
    fwrite(STDERR, '初始化数据表失败：' . $e->getMessage() . "\n");
    exit(1);
}

echo "Tarsk 初始化完成\n";
echo "数据库：{$name}（前缀 {$prefix}）\n";
echo "管理员：{$admin}\n";
echo "访问地址：http://127.0.0.1:{$panelPort}\n";
