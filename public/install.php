<?php
declare(strict_types=1);

/**
 * Tarsk 安装向导
 */

$root = dirname(__DIR__);

function install_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$installed = is_file($root . '/install.lock') && is_file($root . '/config.php');
$message = '';
$messageType = 'error';

$defaults = [
    'host' => '127.0.0.1',
    'port' => '3306',
    'name' => 'tarsk',
    'user' => 'tarsk',
    'pass' => '',
];

$existingConfig = [];
if (is_file($root . '/config.php')) {
    $existingConfig = (array)(require $root . '/config.php');
    $db = $existingConfig['database'] ?? [];
    $defaults = [
        'host' => $db['host'] ?? '127.0.0.1',
        'port' => (string)($db['port'] ?? 3306),
        'name' => $db['name'] ?? 'tarsk',
        'user' => $db['user'] ?? 'tarsk',
        'pass' => $db['pass'] ?? '',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    $host = trim((string)($_POST['host'] ?? ''));
    $port = (int)($_POST['port'] ?? 3306);
    $name = trim((string)($_POST['name'] ?? ''));
    $user = trim((string)($_POST['user'] ?? ''));
    $pass = (string)($_POST['pass'] ?? '');
    $adminUser = trim((string)($_POST['admin_user'] ?? 'admin'));
    $adminPass = (string)($_POST['admin_pass'] ?? '');
    $prefix = 'tarsk_';

    $defaults = compact('host', 'port', 'name', 'user', 'pass');

    if ($name === '' || $user === '' || $adminPass === '') {
        $message = '数据库名、账号与管理员密码不能为空';
    } elseif (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
        $message = '数据库名只能包含字母、数字与下划线';
    } elseif (strlen($adminPass) < 8) {
        $message = '管理员密码至少 8 位';
    } else {
        $pdo = null;
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
                $user, $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (Throwable $e) {
            $firstError = $e->getMessage();
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
            } catch (Throwable $e2) {
                // 无建库权限时回落到 test 库
                try {
                    $pdo = new PDO(
                        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, 'test'),
                        $user, $pass,
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
                    );
                    $name = 'test';
                    $messageType = 'warning';
                    $message = '账号没有建库权限，面板数据将存放在 test 库（表前缀 ' . $prefix . '）。原错误：' . $firstError;
                } catch (Throwable $e3) {
                    $message = '数据库连接失败：' . $e3->getMessage();
                }
            }
        }

        if ($pdo instanceof PDO) {
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
                    'debug' => true,
                    'version' => '1.0.0',
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
            $php = "<?php\n/**\n * Tarsk 配置（安装向导生成）\n */\nreturn " . var_export($config, true) . ";\n";
            if (@file_put_contents($configPath, $php) === false) {
                $message = '无法写入 config.php，请检查目录权限';
            } else {
                $GLOBALS['config'] = $config;
                require $root . '/app/bootstrap.php';
                try {
                    install_schema();
                    seed_data(['username' => $adminUser !== '' ? $adminUser : 'admin', 'password' => $adminPass]);
                    @file_put_contents($root . '/install.lock', date('Y-m-d H:i:s'));
                    header('Location: /login');
                    exit;
                } catch (Throwable $e) {
                    $message = '初始化数据表失败：' . $e->getMessage();
                }
            }
        }
    }
}

$panelVersion = '1.0.0';
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tarsk · 安装向导</title>
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;font-family:-apple-system,"Segoe UI","Microsoft YaHei",sans-serif;background:radial-gradient(1200px 600px at 15% 0%,#1e3a8a 0%,#0b1220 55%),#0b1220;color:#e2e8f0;display:grid;place-items:center;padding:40px 20px}
.shell{width:100%;max-width:760px}
.brand{display:flex;align-items:center;gap:14px;margin-bottom:26px}
.logo{width:46px;height:46px;border-radius:13px;background:linear-gradient(135deg,#38bdf8,#2563eb);display:grid;place-items:center;font-weight:700;font-size:22px;color:#fff;box-shadow:0 10px 30px rgba(37,99,235,.4)}
.brand h1{margin:0;font-size:22px;letter-spacing:.5px}
.brand p{margin:4px 0 0;color:#94a3b8;font-size:13px}
.card{background:rgba(15,23,42,.72);border:1px solid rgba(148,163,184,.2);border-radius:18px;padding:32px;backdrop-filter:blur(10px);box-shadow:0 24px 60px rgba(2,6,23,.55)}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.full{grid-column:span 2}
label{display:block;font-size:12px;color:#94a3b8;margin-bottom:7px;letter-spacing:.4px}
input{width:100%;padding:11px 13px;border-radius:9px;border:1px solid rgba(148,163,184,.25);background:rgba(30,41,59,.6);color:#e2e8f0;font-size:14px;outline:none}
input:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.18)}
.section{margin:22px 0 6px;font-size:13px;color:#cbd5e1;border-top:1px dashed rgba(148,163,184,.2);padding-top:20px}
.alert{padding:12px 14px;border-radius:10px;font-size:13px;margin-bottom:18px;line-height:1.7}
.alert--error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35);color:#fecaca}
.alert--warning{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.35);color:#fde68a}
.alert--ok{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.35);color:#a7f3d0}
button{width:100%;margin-top:22px;padding:13px;border:0;border-radius:10px;background:linear-gradient(135deg,#3b82f6,#2563eb);color:#fff;font-size:15px;font-weight:600;cursor:pointer;letter-spacing:.5px}
button:hover{filter:brightness(1.08)}
.hint{font-size:12px;color:#64748b;line-height:1.8;margin-top:16px}
.installed{text-align:center;padding:10px 0}
.installed a{display:inline-block;margin-top:18px;padding:11px 26px;border-radius:9px;background:#2563eb;color:#fff;text-decoration:none}
code{background:rgba(148,163,184,.18);padding:2px 6px;border-radius:4px;font-size:12px}
@media(max-width:640px){.grid{grid-template-columns:1fr}.full{grid-column:span 1}}
</style>
</head>
<body>
<div class="shell">
  <div class="brand">
    <div class="logo">T</div>
    <div>
      <h1>Tarsk 面板</h1>
      <p>轻量服务器运维面板 · 安装向导</p>
    </div>
  </div>
  <div class="card">
    <?php if ($installed): ?>
      <div class="installed">
        <div class="alert alert--ok">面板已安装完成，可直接进入管理后台。</div>
        <p class="hint">如需重新安装，请删除项目根目录下的 <code>install.lock</code> 后刷新本页。删除前请先备份数据。</p>
        <a href="/login">进入登录页</a>
      </div>
    <?php else: ?>
      <?php if ($message !== ''): ?>
        <div class="alert alert--<?= install_e($messageType) ?>"><?= install_e($message) ?></div>
      <?php endif; ?>
      <form method="post" autocomplete="off">
        <div class="section">数据库连接</div>
        <div class="grid">
          <div>
            <label>MySQL 地址</label>
            <input type="text" name="host" value="<?= install_e($defaults['host']) ?>" required>
          </div>
          <div>
            <label>端口</label>
            <input type="number" name="port" value="<?= install_e($defaults['port']) ?>" required>
          </div>
          <div>
            <label>数据库名</label>
            <input type="text" name="name" value="<?= install_e($defaults['name']) ?>" required>
          </div>
          <div>
            <label>数据库账号</label>
            <input type="text" name="user" value="<?= install_e($defaults['user']) ?>" required>
          </div>
          <div class="full">
            <label>数据库密码</label>
            <input type="password" name="pass" value="<?= install_e($defaults['pass']) ?>">
          </div>
        </div>

        <div class="section">管理员账号</div>
        <div class="grid">
          <div>
            <label>管理员用户名</label>
            <input type="text" name="admin_user" value="admin" required>
          </div>
          <div>
            <label>管理员密码（至少 8 位）</label>
            <input type="password" name="admin_pass" value="" required minlength="8">
          </div>
        </div>

        <button type="submit">开始安装</button>
        <p class="hint">
          安装将自动创建数据表（前缀 <code>tarsk_</code>）。若数据库账号没有建库权限，面板会自动回落到 <code>test</code> 库以保证可用。
          面板自身的监控、配置、日志数据存放在 MySQL；站点数据库由面板通过 MySQL 管理。
        </p>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
