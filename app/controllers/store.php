<?php
declare(strict_types=1);

/**
 * 软件商店：Tarsk 托管运行环境 + 系统软件探测
 */

function managed_soft_keys(): array
{
    return PHP_OS_FAMILY === 'Windows' ? array_keys(runtime_catalog()) : [];
}

function store_linux_package_map(): array
{
    return [
        'nginx' => ['apt' => ['nginx'], 'dnf' => ['nginx'], 'yum' => ['nginx'], 'apk' => ['nginx'], 'pacman' => ['nginx'], 'zypper' => ['nginx']],
        'mysql' => ['apt' => ['mysql-server'], 'dnf' => ['mariadb-server'], 'yum' => ['mariadb-server'], 'apk' => ['mariadb'], 'pacman' => ['mariadb'], 'zypper' => ['mariadb']],
        'php' => ['apt' => ['php-fpm', 'php-cli', 'php-mysql', 'php-mbstring', 'php-xml'], 'dnf' => ['php-fpm', 'php-cli', 'php-mysqlnd', 'php-mbstring', 'php-xml'], 'yum' => ['php-fpm', 'php-cli', 'php-mysqlnd', 'php-mbstring', 'php-xml'], 'apk' => ['php83', 'php83-fpm', 'php83-mysqli', 'php83-mbstring', 'php83-xml'], 'pacman' => ['php', 'php-fpm'], 'zypper' => ['php8', 'php8-fpm', 'php8-mysql', 'php8-mbstring', 'php8-xml']],
        'redis' => ['apt' => ['redis-server'], 'dnf' => ['redis'], 'yum' => ['redis'], 'apk' => ['redis'], 'pacman' => ['redis'], 'zypper' => ['redis']],
        'docker' => ['apt' => ['docker.io'], 'dnf' => ['docker'], 'yum' => ['docker'], 'apk' => ['docker'], 'pacman' => ['docker'], 'zypper' => ['docker']],
        'node' => ['apt' => ['nodejs', 'npm'], 'dnf' => ['nodejs', 'npm'], 'yum' => ['nodejs', 'npm'], 'apk' => ['nodejs', 'npm'], 'pacman' => ['nodejs', 'npm'], 'zypper' => ['nodejs', 'npm']],
        'git' => ['apt' => ['git'], 'dnf' => ['git'], 'yum' => ['git'], 'apk' => ['git'], 'pacman' => ['git'], 'zypper' => ['git']],
        'composer' => ['apt' => ['composer'], 'dnf' => ['composer'], 'yum' => ['composer'], 'apk' => ['composer'], 'pacman' => ['composer'], 'zypper' => ['composer']],
        'openssl' => ['apt' => ['openssl'], 'dnf' => ['openssl'], 'yum' => ['openssl'], 'apk' => ['openssl'], 'pacman' => ['openssl'], 'zypper' => ['openssl']],
        'python' => ['apt' => ['python3'], 'dnf' => ['python3'], 'yum' => ['python3'], 'apk' => ['python3'], 'pacman' => ['python'], 'zypper' => ['python3']],
    ];
}

function store_linux_manager(): ?array
{
    if (PHP_OS_FAMILY !== 'Linux') { return null; }
    foreach ([
        'apt' => ['apt-get', 'install', '-y', '--no-install-recommends'],
        'dnf' => ['dnf', 'install', '-y'],
        'yum' => ['yum', 'install', '-y'],
        'apk' => ['apk', 'add', '--no-progress'],
        'pacman' => ['pacman', '--noconfirm', '--needed', '-S'],
        'zypper' => ['zypper', '--non-interactive', 'install', '-y'],
    ] as $key => $spec) {
        $bin = command_exists($spec[0]);
        if ($bin !== null) { return ['key' => $key, 'bin' => $bin, 'args' => array_slice($spec, 1)]; }
    }
    return null;
}

function store_install_status_path(string $key): string
{
    return storage_path('logs/install_' . preg_replace('/[^a-z0-9_-]/i', '', $key) . '.status.json');
}

function store_install_status_data(string $key): array
{
    $path = store_install_status_path($key);
    $raw = is_file($path) ? (string)@file_get_contents($path) : '';
    $data = $raw !== '' ? json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $raw), true) : null;
    return is_array($data) ? $data : [];
}

function store_start_system_install(array $app): array
{
    $key = (string)$app['key'];
    $map = store_linux_package_map()[$key] ?? [];
    $log = storage_path('logs/install_' . $key . '.log');
    $statusPath = store_install_status_path($key);
    @file_put_contents($statusPath, json_encode(['status' => 'queued', 'started_at' => now()], JSON_UNESCAPED_SLASHES), LOCK_EX);

    if (PHP_OS_FAMILY === 'Linux') {
        $manager = store_linux_manager();
        if ($manager === null || empty($map[$manager['key']])) {
            @unlink($statusPath);
            return ['ok' => false, 'status' => 501, 'message' => '未检测到支持此软件的 Linux 包管理器/软件源'];
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : (int)trim((string)(run_cmd('id -u 2>/dev/null', 5)['output'] ?? '-1'));
        if ($uid !== 0) {
            @unlink($statusPath);
            return ['ok' => false, 'status' => 403, 'message' => '系统软件安装需要 root 权限；请以 root 运行 Tarsk 服务，面板不会尝试 sudo 或交互式提权'];
        }
        $scriptPath = storage_path('tmp/install_' . $key . '.sh');
        $statusQ = escapeshellarg($statusPath);
        $logQ = escapeshellarg($log);
        $command = escapeshellarg($manager['bin']) . ' ' . implode(' ', array_map('escapeshellarg', $manager['args']))
            . ' ' . implode(' ', array_map('escapeshellarg', $map[$manager['key']]));
        $script = "#!/bin/sh\nset +e\nstarted=\$(date '+%Y-%m-%dT%H:%M:%S%z')\nprintf '{\"status\":\"running\",\"started_at\":\"%s\"}\\n' \"\$started\" > " . $statusQ . "\n"
            . ($manager['key'] === 'apt' ? "export DEBIAN_FRONTEND=noninteractive\n" : '')
            . $command . ' >> ' . $logQ . " 2>&1\nrc=\$?\n"
            . "if [ \$rc -eq 0 ]; then state=succeeded; else state=failed; fi\n"
            . "finished=\$(date '+%Y-%m-%dT%H:%M:%S%z')\nprintf '{\"status\":\"%s\",\"exit_code\":%s,\"finished_at\":\"%s\"}\\n' \"\$state\" \"\$rc\" \"\$finished\" > " . $statusQ . "\nexit \$rc\n";
        if (@file_put_contents($scriptPath, $script, LOCK_EX) === false) {
            @unlink($statusPath);
            return ['ok' => false, 'status' => 500, 'message' => '无法创建包管理器安装脚本'];
        }
        @chmod($scriptPath, 0700);
        @file_put_contents($log, '', LOCK_EX);
        $launch = run_cmd('nohup /bin/sh ' . escapeshellarg($scriptPath) . ' >/dev/null 2>&1 </dev/null & echo $!', 8);
        $pid = (int)trim($launch['output']);
        if (!$launch['ok'] || $launch['code'] !== 0 || $pid <= 0) {
            @file_put_contents($statusPath, json_encode(['status' => 'failed', 'message' => trim($launch['error'] ?: $launch['output'] ?: '后台任务启动失败')], JSON_UNESCAPED_SLASHES), LOCK_EX);
            return ['ok' => false, 'status' => 502, 'message' => '无法启动 Linux 软件安装任务：' . ($launch['error'] ?: $launch['output'] ?: '未获得进程号')];
        }
        return ['ok' => true, 'status' => 202, 'message' => '已提交 ' . $manager['key'] . ' 安装任务，完成状态和输出将写入日志', 'pid' => $pid, 'log' => $log, 'package_manager' => $manager['key']];
    }

    if (PHP_OS_FAMILY !== 'Windows') {
        @unlink($statusPath);
        return ['ok' => false, 'status' => 501, 'message' => '当前操作系统暂不支持自动安装'];
    }
    if (command_exists('winget') === null || trim((string)($app['winget'] ?? '')) === '') {
        @unlink($statusPath);
        return ['ok' => false, 'status' => 501, 'message' => '该软件没有可用的 winget 安装源'];
    }
    $id = (string)$app['winget'];
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $id)) {
        @unlink($statusPath);
        return ['ok' => false, 'status' => 422, 'message' => '软件源 ID 不合法'];
    }
    $wrapper = storage_path('tmp/install_' . $key . '.ps1');
    $psQuote = static fn(string $v): string => "'" . str_replace("'", "''", $v) . "'";
    $wrapperBody = '$ErrorActionPreference = "Continue"' . "\n"
        . '@{ status = "running"; started_at = (Get-Date -Format o) } | ConvertTo-Json -Compress | Set-Content -LiteralPath ' . $psQuote($statusPath) . " -Encoding UTF8\n"
        . '& winget install --id ' . $psQuote($id) . ' --exact --silent --accept-package-agreements --accept-source-agreements --disable-interactivity *> ' . $psQuote($log) . "\n"
        . '$code = $LASTEXITCODE' . "\n"
        . '$state = if ($code -eq 0) { "succeeded" } else { "failed" }' . "\n"
        . '@{ status = $state; exit_code = $code; finished_at = (Get-Date -Format o) } | ConvertTo-Json -Compress | Set-Content -LiteralPath ' . $psQuote($statusPath) . " -Encoding UTF8\n"
        . 'exit $code' . "\n";
    if (@file_put_contents($wrapper, "\xEF\xBB\xBF" . $wrapperBody, LOCK_EX) === false) {
        @unlink($statusPath);
        return ['ok' => false, 'status' => 500, 'message' => '无法创建 winget 安装脚本'];
    }
    @file_put_contents($log, '', LOCK_EX);
    $launch = ps_run('$p = Start-Process -FilePath "powershell.exe" -ArgumentList @("-NoProfile","-NonInteractive","-ExecutionPolicy","Bypass","-File",' . $psQuote($wrapper) . ') -WindowStyle Hidden -PassThru; $p.Id', 15);
    $pid = 0;
    foreach (preg_split('/\r?\n/', trim($launch['output'])) as $line) { if (ctype_digit(trim($line))) { $pid = (int)trim($line); } }
    if (empty($launch['ok']) || $pid <= 0) {
        @file_put_contents($statusPath, json_encode(['status' => 'failed', 'message' => trim($launch['error'] ?: $launch['output'] ?: '后台任务启动失败')], JSON_UNESCAPED_SLASHES), LOCK_EX);
        return ['ok' => false, 'status' => 502, 'message' => '无法启动 winget 安装进程：' . ($launch['error'] ?: $launch['output'] ?: '未获得进程号')];
    }
    return ['ok' => true, 'status' => 202, 'message' => '已提交 winget 安装任务，完成状态和输出将写入日志', 'pid' => $pid, 'log' => $log, 'package_manager' => 'winget'];
}

function store_page(): void
{
    render('store', [
        'title' => '软件商店',
        'active' => 'store',
        'managed' => PHP_OS_FAMILY === 'Windows' ? runtime_states() : [],
        'apps' => store_probe_all(),
        'winget' => command_exists('winget'),
        'platform' => PHP_OS_FAMILY,
        'install_logs' => store_install_logs(),
        'ports' => [
            'nginx' => runtime_port('nginx'),
            'php' => runtime_port('php'),
            'redis' => runtime_port('redis'),
        ],
    ], 'app');
}

function store_probe_all(bool $fresh = false): array
{
    if (!$fresh) {
        $cached = cache_get('store_catalog', 120);
        if ($cached !== null) {
            foreach ($cached as &$cachedRow) {
                $cachedRow['install_status'] = store_install_status_data((string)($cachedRow['key'] ?? ''));
            }
            unset($cachedRow);
            return $cached;
        }
    }

    $rows = [];
    $linuxManager = store_linux_manager();
    foreach (soft_catalog() as $item) {
        if (in_array($item['key'], managed_soft_keys(), true)) {
            continue;
        }
        $probe = ($item['probe'])();
        $row = [
            'key' => $item['key'],
            'name' => $item['name'],
            'category' => $item['category'],
            'desc' => $item['desc'],
            'winget' => $item['winget'],
            'url' => $item['url'],
            'installed' => (bool)$probe['installed'],
            'version' => (string)$probe['version'],
            'path' => (string)$probe['path'],
            'linux_installable' => PHP_OS_FAMILY === 'Linux' && $linuxManager !== null && !empty((store_linux_package_map()[$item['key']] ?? [])[$linuxManager['key']]),
            'install_status' => store_install_status_data((string)$item['key']),
        ];
        $rows[] = $row;

        execute(
            'INSERT INTO ' . t('soft') . ' (app_key, name, version, path, status, checked_at) VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), version = VALUES(version), path = VALUES(path),
             status = VALUES(status), checked_at = VALUES(checked_at)',
            [
                $row['key'], $row['name'], mb_substr($row['version'], 0, 60),
                mb_substr($row['path'], 0, 255), $row['installed'] ? 'installed' : 'missing', now(),
            ]
        );
    }

    cache_set('store_catalog', $rows);
    return $rows;
}

/* --------------------------- Tarsk 托管运行环境 --------------------------- */

function store_managed_install(): void
{
    $key = (string)($_POST['key'] ?? '');
    if (!runtime_package($key)) {
        flash('error', '未知的软件包');
        redirect('/store');
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        flash('error', '托管安装当前仅支持 Windows');
        redirect('/store');
    }

    $result = runtime_install($key);
    cache_forget('store_catalog');
    log_action('安装运行环境', $key . ' · ' . ($result['ok'] ? 'success' : 'failed'), $result['ok'] ? 'info' : 'warning');
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/store');
}

function store_managed_action(): void
{
    $key = (string)($_POST['key'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    if (!runtime_package($key)) {
        flash('error', '未知的软件包');
        redirect('/store');
    }

    $allowed = ['start', 'stop', 'restart', 'uninstall', 'open'];
    if (!in_array($action, $allowed, true)) {
        flash('error', '不支持的操作');
        redirect('/store');
    }

    switch ($action) {
        case 'start':
            $result = runtime_start($key);
            break;
        case 'stop':
            $result = runtime_stop($key);
            break;
        case 'restart':
            $result = runtime_restart($key);
            break;
        case 'uninstall':
            $result = runtime_uninstall($key);
            break;
        default:
            $result = runtime_open_dir($key);
    }

    cache_forget('store_catalog');
    log_action('运行环境操作', $key . ' · ' . $action . ' · ' . ($result['ok'] ? 'ok' : 'failed'), $result['ok'] ? 'info' : 'warning');
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/store');
}

function store_log_api(): void
{
    $key = (string)($_GET['key'] ?? '');
    if (!runtime_package($key)) {
        json_response(['ok' => false, 'message' => '未知的软件包'], 400);
    }
    $state = runtime_state($key);
    json_response([
        'ok' => true,
        'key' => $key,
        'name' => $state['name'],
        'running' => $state['running'],
        'path' => $state['log_path'],
        'tail' => runtime_log_tail($key, 120),
    ]);
}

/* ---------------------------- 系统软件安装 ---------------------------- */

function store_install(): void
{
    $key = (string)($_POST['key'] ?? '');
    $app = null;
    foreach (soft_catalog() as $item) {
        if ($item['key'] === $key) {
            $app = $item;
            break;
        }
    }
    if (!$app) {
        flash('error', '未知的软件包');
        redirect('/store');
    }
    $result = store_start_system_install($app);
    cache_forget('store_catalog');
    log_action('启动软件安装', $app['name'] . ' · ' . ($result['package_manager'] ?? '未启动'), $result['ok'] ? 'info' : 'warning');
    flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $app['name'] . '：' . $result['message'] : $result['message']);
    redirect('/store');
}

function store_check(): void
{
    $key = (string)($_POST['key'] ?? '');
    if ($key === 'all') {
        cache_forget('store_catalog');
        store_probe_all(true);
        flash('success', '已完成全部软件重新检测');
        redirect('/store');
    }
    $probe = soft_check($key);
    if ($probe === null) {
        flash('error', '未知的软件包');
        redirect('/store');
    }
    foreach (soft_catalog() as $item) {
        if ($item['key'] === $key) {
            store_persist_probe($item, $probe);
            break;
        }
    }
    cache_forget('store_catalog');
    flash($probe['installed'] ? 'success' : 'warning', $probe['installed']
        ? ('已检测到安装：' . $probe['version'])
        : '尚未检测到安装完成，请稍后再试');
    redirect('/store');
}

function store_install_logs(): array
{
    $logs = [];
    foreach (glob(storage_path('logs/install_*.log')) ?: [] as $file) {
        $key = preg_replace('/^install_|\..*$/', '', basename($file));
        $tail = '';
        $size = filesize($file);
        if ($size > 0) {
            $handle = fopen($file, 'rb');
            fseek($handle, max(0, $size - 4096));
            $tail = (string)fread($handle, 4096);
            fclose($handle);
        }
        $state = store_install_status_data((string)$key);
        $statusText = ['queued' => '排队中', 'running' => '安装中', 'succeeded' => '安装成功', 'failed' => '安装失败'][(string)($state['status'] ?? '')] ?? '状态未知';
        if (!empty($state['message'])) { $tail = trim($tail . "\n" . $state['message']); }
        $logs[] = ['key' => $key, 'name' => basename($file), 'tail' => trim($tail), 'status' => $statusText, 'mtime' => date('m-d H:i', (int)filemtime($file))];
    }
    return $logs;
}


/* ----------------------------- Session JSON API ----------------------------- */

function store_api_index(): void
{
    header('Cache-Control: no-store');
    json_response([
        'ok' => true,
        'managed' => PHP_OS_FAMILY === 'Windows' ? runtime_states() : [],
        'apps' => store_probe_all(),
        'platform' => PHP_OS_FAMILY,
        'winget_available' => command_exists('winget') !== null,
        'checked_at' => now(),
        'csrf_token' => csrf_token(),
    ]);
}

function store_api_check(): void
{
    $key = trim((string)($_POST['key'] ?? ''));
    if ($key === 'all') {
        cache_forget('store_catalog');
        $apps = store_probe_all(true);
        json_response(['ok' => true, 'message' => '已完成全部软件检测', 'apps' => $apps, 'managed' => PHP_OS_FAMILY === 'Windows' ? runtime_states() : []]);
    }
    if (PHP_OS_FAMILY === 'Windows' && runtime_package($key)) {
        $state = runtime_state($key);
        json_response(['ok' => true, 'item' => $state]);
    }
    $probe = soft_check($key);
    if ($probe === null) {
        json_response(['ok' => false, 'message' => '未知的软件包'], 404);
    }
    foreach (soft_catalog() as $entry) {
        if ($entry['key'] === $key) {
            store_persist_probe($entry, $probe);
            break;
        }
    }
    cache_forget('store_catalog');
    json_response(['ok' => true, 'message' => $probe['installed'] ? '检测到软件已安装' : '未检测到软件安装', 'item' => [
        'key' => $key, 'installed' => (bool)$probe['installed'], 'version' => (string)$probe['version'], 'path' => (string)$probe['path'],
    ]]);
}

function store_api_action(): void
{
    $key = trim((string)($_POST['key'] ?? ''));
    $action = trim((string)($_POST['action'] ?? ''));
    if (PHP_OS_FAMILY !== 'Windows' || !runtime_package($key)) {
        json_response(['ok' => false, 'message' => '未知的托管运行环境'], 404);
    }
    $operations = [
        'start' => 'runtime_start',
        'stop' => 'runtime_stop',
        'restart' => 'runtime_restart',
        'uninstall' => 'runtime_uninstall',
        'open' => 'runtime_open_dir',
    ];
    if (!isset($operations[$action])) {
        json_response(['ok' => false, 'message' => '不支持的操作'], 422);
    }
    $result = $operations[$action]($key);
    cache_forget('store_catalog');
    log_action('运行环境 API 操作', $key . ' · ' . $action, $result['ok'] ? 'info' : 'warning');
    $status = $result['ok'] ? 200 : 502;
    json_response(['ok' => (bool)$result['ok'], 'message' => (string)$result['message'], 'item' => runtime_state($key)], $status);
}

function store_api_install(): void
{
    $key = trim((string)($_POST['key'] ?? ''));
    if (PHP_OS_FAMILY === 'Windows' && runtime_package($key)) {
        $result = runtime_install($key);
        cache_forget('store_catalog');
        log_action('安装运行环境 API', $key, $result['ok'] ? 'info' : 'warning');
        json_response(['ok' => (bool)$result['ok'], 'message' => (string)$result['message'], 'item' => runtime_state($key)], $result['ok'] ? 200 : 502);
    }

    $app = null;
    foreach (soft_catalog() as $entry) {
        if ($entry['key'] === $key) { $app = $entry; break; }
    }
    if (!$app) {
        json_response(['ok' => false, 'message' => '未知的软件包'], 404);
    }
    $result = store_start_system_install($app);
    cache_forget('store_catalog');
    log_action('启动软件安装 API', $app['name'] . ' · ' . ($result['package_manager'] ?? '未启动'), $result['ok'] ? 'info' : 'warning');
    json_response($result + ['key' => $key], $result['ok'] ? 202 : (int)($result['status'] ?? 502));
}

function store_api_install_status(): void
{
    header('Cache-Control: no-store');
    $key = trim((string)($_GET['key'] ?? ''));
    $app = null;
    foreach (soft_catalog() as $entry) {
        if ($entry['key'] === $key) { $app = $entry; break; }
    }
    if ($app === null) { json_response(['ok' => false, 'message' => '未知的软件包'], 404); }
    $status = store_install_status_data($key);
    if ($status === []) { json_response(['ok' => false, 'message' => '该软件没有安装任务记录'], 404); }
    $logPath = storage_path('logs/install_' . $key . '.log');
    $tail = '';
    if (is_file($logPath)) {
        $size = (int)@filesize($logPath);
        $handle = @fopen($logPath, 'rb');
        if ($handle) { fseek($handle, max(0, $size - 8192)); $tail = (string)fread($handle, 8192); fclose($handle); }
    }
    json_response(['ok' => true, 'key' => $key, 'status' => $status, 'log' => trim($tail)]);
}

function store_persist_probe(array $item, array $probe): void
{
    execute(
        'INSERT INTO ' . t('soft') . ' (app_key, name, version, path, status, checked_at) VALUES (?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), version = VALUES(version), path = VALUES(path), status = VALUES(status), checked_at = VALUES(checked_at)',
        [
            (string)$item['key'], (string)$item['name'], mb_substr((string)($probe['version'] ?? ''), 0, 60),
            mb_substr((string)($probe['path'] ?? ''), 0, 255), !empty($probe['installed']) ? 'installed' : 'missing', now(),
        ]
    );
}
