<?php
declare(strict_types=1);

/**
 * Tarsk 托管运行环境：下载、安装、配置、启停、卸载
 * 支持 Nginx / PHP-CGI / Redis（Windows）
 */

function runtime_soft_dir(): string
{
    $dir = storage_path('soft');
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return str_replace('\\', '/', $dir);
}

function runtime_php_versions(): array
{
    return [
        '7.4' => [
            'key' => 'php74',
            'label' => '7.4.33',
            'url' => 'https://windows.php.net/downloads/releases/archives/php-7.4.33-nts-Win32-vc15-x64.zip',
            'port_setting' => 'php_port_74',
            'port' => 9074,
        ],
        '8.0' => [
            'key' => 'php80',
            'label' => '8.0.30',
            'url' => 'https://windows.php.net/downloads/releases/archives/php-8.0.30-nts-Win32-vs16-x64.zip',
            'port_setting' => 'php_port_80',
            'port' => 9080,
        ],
        '8.1' => [
            'key' => 'php81',
            'label' => '8.1 最新稳定版',
            'url' => 'https://windows.php.net/downloads/releases/latest/php-8.1-nts-Win32-vs16-x64-latest.zip',
            'port_setting' => 'php_port_81',
            'port' => 9081,
        ],
        '8.2' => [
            'key' => 'php82',
            'label' => '8.2 最新稳定版',
            'url' => 'https://windows.php.net/downloads/releases/latest/php-8.2-nts-Win32-vs16-x64-latest.zip',
            'port_setting' => 'php_port_82',
            'port' => 9082,
        ],
        '8.3' => [
            'key' => 'php',
            'label' => '8.3 最新稳定版',
            'url' => 'https://windows.php.net/downloads/releases/latest/php-8.3-nts-Win32-vs16-x64-latest.zip',
            'port_setting' => 'php_port',
            'port' => 9000,
        ],
        '8.4' => [
            'key' => 'php84',
            'label' => '8.4 最新稳定版',
            'url' => 'https://windows.php.net/downloads/releases/latest/php-8.4-nts-Win32-vs16-x64-latest.zip',
            'port_setting' => 'php_port_84',
            'port' => 9084,
        ],
    ];
}

function runtime_php_key(string $version): string
{
    $versions = runtime_php_versions();
    return $versions[$version]['key'] ?? 'php';
}

function runtime_catalog(): array
{
    $catalog = [
        'nginx' => [
            'name' => 'Nginx',
            'version' => '1.26.2',
            'url' => 'https://nginx.org/download/nginx-1.26.2.zip',
            'size' => '约 2 MB',
            'port_setting' => 'nginx_port',
            'default_port' => 8080,
            'icon' => 'globe',
            'desc' => '高性能 Web 服务器。安装后 Tarsk 站点会由它托管，重新加载配置无需重启。',
        ],
    ];
    foreach (runtime_php_versions() as $ver => $info) {
        $catalog[$info['key']] = [
            'name' => 'PHP ' . $ver,
            'version' => $info['label'],
            'url' => $info['url'],
            'size' => '约 34 MB',
            'port_setting' => $info['port_setting'],
            'default_port' => $info['port'],
            'icon' => 'terminal',
            'php_version' => $ver,
            'desc' => 'PHP-CGI ' . $ver . ' 运行环境。多版本可并行安装，每个站点可独立选择 PHP 版本；预启用 PDO_MySQL、OpenSSL、mbstring 等常用扩展。',
        ];
    }
    $catalog['redis'] = [
        'name' => 'Redis',
        'version' => '5.0.14.1',
        'url' => 'https://github.com/tporadowski/redis/releases/download/v5.0.14.1/Redis-x64-5.0.14.1.zip',
        'size' => '约 12 MB',
        'port_setting' => 'redis_port',
        'default_port' => 6380,
        'icon' => 'zap',
        'desc' => '内存键值缓存，用于会话、队列与对象缓存。默认监听 127.0.0.1:6380。',
    ];
    return $catalog;
}

function runtime_package(string $key): ?array
{
    return runtime_catalog()[$key] ?? null;
}

function runtime_root(string $key): string
{
    return runtime_soft_dir() . '/' . $key;
}

function runtime_binary(string $key): string
{
    if (str_starts_with($key, 'php')) {
        return 'php-cgi.exe';
    }
    return [
        'nginx' => 'nginx.exe',
        'redis' => 'redis-server.exe',
    ][$key] ?? '';
}

function managed_nginx_root(): ?string
{
    $root = runtime_root('nginx');
    return is_file($root . '/nginx.exe') ? $root : null;
}

function managed_nginx_installed(): bool
{
    return managed_nginx_root() !== null;
}

function managed_php_root(): ?string
{
    $root = runtime_root('php');
    return is_file($root . '/php-cgi.exe') ? $root : null;
}

function managed_php_installed(): bool
{
    return managed_php_root() !== null;
}

function managed_redis_root(): ?string
{
    $root = runtime_root('redis');
    return is_file($root . '/redis-server.exe') ? $root : null;
}

function managed_redis_installed(): bool
{
    return managed_redis_root() !== null;
}

function runtime_installed(string $key): bool
{
    $root = runtime_root($key);
    $binary = runtime_binary($key);
    return $binary !== '' && is_file($root . '/' . $binary);
}

function runtime_port(string $key): int
{
    $pkg = runtime_package($key);
    return (int)setting($pkg['port_setting'] ?? '', (int)($pkg['default_port'] ?? 0));
}

/* ------------------------------- 进程状态 ------------------------------- */

function runtime_pid_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $result = run_cmd('tasklist /FI "PID eq ' . $pid . '" /NH /FO CSV', 8);
        $text = trim($result['output']);
        if ($text === '' || stripos($text, 'No tasks') !== false || stripos($text, 'INFO:') !== false) {
            return false;
        }
        return (bool)preg_match('/,"' . $pid . '"/', $text);
    }
    return is_dir('/proc/' . $pid);
}

function runtime_db_row(string $key): ?array
{
    return fetch_one('SELECT * FROM ' . t('soft') . ' WHERE app_key = ? AND managed = 1', [$key]);
}

/**
 * 通过 netstat 反查本机监听端口对应的 PID（Windows），用于托管进程发现
 */
function runtime_find_pid_by_port(int $port): int
{
    if (PHP_OS_FAMILY !== 'Windows') {
        $result = run_cmd("ss -ltnp 2>/dev/null | grep ':$port '", 8);
        if (preg_match('/pid=(\d+)/', $result['output'], $m)) {
            return (int)$m[1];
        }
        return 0;
    }
    $result = run_cmd('netstat -ano -p TCP', 10);
    foreach (preg_split('/\r?\n/', $result['output']) as $line) {
        if (preg_match('/127\.0\.0\.1:' . $port . '\s+\S+\s+LISTENING\s+(\d+)/', trim($line), $m)) {
            return (int)$m[1];
        }
    }
    return 0;
}

function runtime_save_db(string $key, array $values): void
{
    $pkg = runtime_package($key);
    if (!$pkg) {
        return;
    }
    $row = runtime_db_row($key) ?: [];
    $data = array_merge([
        'name' => $pkg['name'],
        'version' => (string)($row['version'] ?? ''),
        'path' => runtime_root($key),
        'port' => runtime_port($key),
        'pid' => (int)($row['pid'] ?? 0),
        'log_path' => runtime_log_path($key),
        'status' => (string)($row['status'] ?? 'missing'),
    ], $values);
    $data['version'] = mb_substr((string)$data['version'], 0, 60);

    execute(
        'INSERT INTO ' . t('soft') . '
         (app_key, name, version, path, port, pid, managed, log_path, status, checked_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), version = VALUES(version), path = VALUES(path),
         port = VALUES(port), pid = VALUES(pid), managed = VALUES(managed), log_path = VALUES(log_path),
         status = VALUES(status), checked_at = VALUES(checked_at), updated_at = VALUES(updated_at)',
        [
            $key,
            $data['name'],
            $data['version'],
            mb_substr((string)$data['path'], 0, 255),
            (int)$data['port'],
            max(0, (int)$data['pid']),
            1,
            mb_substr((string)$data['log_path'], 0, 255),
            $data['status'],
            now(),
            now(),
        ]
    );
}

/**
 * 组合运行状态：文件、进程、数据库三种来源取最新
 */
function runtime_state(string $key): array
{
    $pkg = runtime_catalog()[$key] ?? null;
    if (!$pkg) {
        return ['key' => $key, 'installed' => false, 'running' => false, 'status' => 'missing'];
    }
    $row = runtime_db_row($key);
    $installed = runtime_installed($key);
    $pid = (int)($row['pid'] ?? 0);
    if ($key === 'nginx' && is_file(runtime_root('nginx') . '/logs/nginx.pid')) {
        $filePid = (int)trim((string)file_get_contents(runtime_root('nginx') . '/logs/nginx.pid'));
        if ($filePid > 0 && runtime_pid_alive($filePid)) {
            $pid = $filePid;
        }
    }
    $running = $installed && runtime_pid_alive($pid);
    if ($installed && !$running && str_starts_with($key, 'php')) {
        $found = runtime_find_pid_by_port(runtime_port($key));
        if ($found > 0) {
            $pid = $found;
            $running = true;
            runtime_save_db($key, ['status' => 'running', 'pid' => $pid]);
        }
    }

    return [
        'key' => $key,
        'name' => $pkg['name'],
        'version' => (string)($row['version'] ?? $pkg['version']),
        'path' => runtime_root($key),
        'port' => runtime_port($key),
        'pid' => $pid,
        'installed' => $installed,
        'running' => $running,
        'status' => !$installed ? 'missing' : ($running ? 'running' : 'stopped'),
        'log_path' => runtime_log_path($key),
        'size' => $pkg['size'],
        'desc' => $pkg['desc'],
        'icon' => $pkg['icon'],
        'url' => $pkg['url'],
    ];
}

function runtime_states(): array
{
    $states = [];
    foreach (runtime_catalog() as $key => $pkg) {
        $states[$key] = runtime_state($key);
    }
    return $states;
}

/* ------------------------------- 日志路径 ------------------------------- */

function runtime_log_path(string $key): string
{
    $root = runtime_root($key);
    if (str_starts_with($key, 'php')) {
        return $root . '/logs/php_error.log';
    }
    return [
        'nginx' => $root . '/logs/error.log',
        'redis' => $root . '/logs/redis.log',
    ][$key] ?? '';
}

function runtime_log_tail(string $key, int $lines = 60): string
{
    $path = runtime_log_path($key);
    if (!is_file($path)) {
        $alt = [
            'php' => runtime_root('php') . '/logs/php.err.log',
            'nginx' => runtime_root('nginx') . '/logs/access.log',
        ][$key] ?? '';
        $path = $alt !== '' && is_file($alt) ? $alt : '';
    }
    if ($path === '' || !is_file($path)) {
        return '';
    }
    $size = (int)filesize($path);
    $handle = fopen($path, 'rb');
    if (!$handle) {
        return '';
    }
    $offset = max(0, $size - 120000);
    fseek($handle, $offset);
    $content = (string)fread($handle, 120000);
    fclose($handle);

    $rows = preg_split('/\r?\n/', trim($content));
    return implode("\n", array_slice($rows, -$lines));
}

/* -------------------------------- 安装 --------------------------------- */

function runtime_install(string $key): array
{
    $pkg = runtime_package($key);
    if (!$pkg) {
        return ['ok' => false, 'message' => '未知的软件包'];
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        return ['ok' => false, 'message' => '托管安装当前仅支持 Windows，Linux 请使用 apt / yum 安装对应服务'];
    }
    if (runtime_installed($key)) {
        return ['ok' => false, 'message' => $pkg['name'] . ' 已安装，无需重复安装'];
    }

    @set_time_limit(0);
    runtime_save_db($key, ['status' => 'installing', 'pid' => 0]);
    $root = runtime_root($key);
    $zip = storage_path('tmp/' . $key . '_' . date('YmdHis') . '.zip');
    $extractDir = storage_path('tmp/extract_' . $key);

    $downloadScript = "\$ProgressPreference='SilentlyContinue'\n"
        . "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12\n"
        . "Invoke-WebRequest -Uri '" . $pkg['url'] . "' -OutFile '" . str_replace("'", "''", $zip) . "' -UseBasicParsing\n"
        . "Write-Output 'DOWNLOAD_OK'";
    $download = ps_run($downloadScript, 900);
    if (!is_file($zip) || filesize($zip) < 500000) {
        @unlink($zip);
        runtime_save_db($key, ['status' => 'missing']);
        return ['ok' => false, 'message' => '下载失败：' . ($download['error'] ?: $download['output'] ?: '文件不存在')];
    }

    delete_path($extractDir);
    @mkdir($extractDir, 0777, true);
    $unzip = ps_run(
        "Expand-Archive -Path '" . str_replace("'", "''", $zip) . "' -DestinationPath '"
        . str_replace("'", "''", $extractDir) . "' -Force\nWrite-Output 'UNZIP_OK'",
        600
    );
    if (!is_dir($extractDir)) {
        @unlink($zip);
        runtime_save_db($key, ['status' => 'missing']);
        return ['ok' => false, 'message' => '解压失败：' . ($unzip['error'] ?: $unzip['output'])];
    }

    // zip 内可能包含单层目录
    $entries = array_values(array_diff(scandir($extractDir) ?: [], ['.', '..']));
    $source = $extractDir;
    if (count($entries) === 1 && is_dir($extractDir . '/' . $entries[0])) {
        $source = $extractDir . '/' . $entries[0];
    }

    delete_path($root);
    @mkdir(dirname($root), 0777, true);
    $moved = @rename($source, $root);
    if (!$moved) {
        @mkdir($root, 0777, true);
        $moved = @rename($source . '/', $root . '/');
    }
    delete_path($extractDir);
    @unlink($zip);

    if (!runtime_installed($key)) {
        runtime_save_db($key, ['status' => 'missing']);
        return ['ok' => false, 'message' => '安装包结构与预期不符，未找到可执行文件'];
    }

    $version = $pkg['version'];
    if (str_starts_with($key, 'php')) {
        runtime_configure_php($root);
        $version = runtime_version($key) ?: $pkg['version'];
    } else {
        switch ($key) {
            case 'nginx':
                runtime_configure_nginx($root);
                $version = runtime_version('nginx') ?: $pkg['version'];
                break;
            case 'redis':
                runtime_configure_redis($root);
                $version = runtime_version('redis') ?: $pkg['version'];
                break;
        }
    }

    if (!is_dir(dirname(runtime_log_path($key)))) {
        @mkdir(dirname(runtime_log_path($key)), 0777, true);
    }

    runtime_save_db($key, [
        'version' => $version,
        'status' => 'stopped',
        'pid' => 0,
        'log_path' => runtime_log_path($key),
        'port' => runtime_port($key),
    ]);

    if (function_exists('sync_all_site_vhosts')) {
        sync_all_site_vhosts();
    }

    return ['ok' => true, 'message' => $pkg['name'] . ' 安装完成（' . $version . '），端口 ' . runtime_port($key)];
}

function runtime_version(string $key): string
{
    $root = runtime_root($key);
    if (str_starts_with($key, 'php')) {
        $command = escapeshellarg($root . '/php.exe') . ' -v 2>&1';
    } else {
        $commands = [
            'nginx' => escapeshellarg($root . '/nginx.exe') . ' -v 2>&1',
            'redis' => escapeshellarg($root . '/redis-server.exe') . ' --version 2>&1',
        ];
        $command = $commands[$key] ?? '';
    }
    if ($command === '' || !is_file($root . '/' . runtime_binary($key))) {
        return '';
    }
    $res = run_cmd($command, 15);
    $text = trim($res['output'] . ' ' . $res['error']);
    if (preg_match('/([0-9]+\.){1,3}[0-9]+/', $text, $m)) {
        return $m[0];
    }
    return '';
}

/* ------------------------------ 各软件配置 ------------------------------ */

function runtime_configure_nginx(string $root): void
{
    foreach (['conf/vhosts', 'logs', 'temp'] as $dir) {
        @mkdir($root . '/' . $dir, 0777, true);
    }

    $port = runtime_port('nginx');
    $main = "worker_processes  1;\n"
        . "error_log  logs/error.log warn;\n"
        . "pid        logs/nginx.pid;\n"
        . "events {\n    worker_connections  1024;\n}\n"
        . "http {\n"
        . "    include       mime.types;\n"
        . "    default_type  application/octet-stream;\n"
        . "    sendfile      on;\n"
        . "    keepalive_timeout  65;\n"
        . "    access_log    logs/access.log;\n"
        . "    include       vhosts/*.conf;\n"
        . "}\n";
    file_put_contents($root . '/conf/nginx.conf', $main);

    $default = "server {\n"
        . "    listen " . $port . " default_server;\n"
        . "    server_name _;\n"
        . "    return 444;\n"
        . "}\n";
    file_put_contents($root . '/conf/vhosts/00-tarsk-default.conf', $default);
}

function runtime_configure_php(string $root): void
{
    @mkdir($root . '/logs', 0777, true);
    @mkdir($root . '/tmp', 0777, true);
    $phpIni = "[PHP]\n"
        . "engine = On\n"
        . "short_open_tag = Off\n"
        . "precision = 14\n"
        . "output_buffering = 4096\n"
        . "zlib.output_compression = Off\n"
        . "implicit_flush = Off\n"
        . "max_execution_time = 300\n"
        . "max_input_time = 300\n"
        . "memory_limit = 512M\n"
        . "error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT\n"
        . "display_errors = Off\n"
        . "log_errors = On\n"
        . "error_log = \"" . str_replace('\\', '/', $root) . "/logs/php_error.log\"\n"
        . "post_max_size = 64M\n"
        . "upload_max_filesize = 64M\n"
        . "extension_dir = \"" . str_replace('\\', '/', $root) . "/ext\"\n";
    foreach (['curl', 'fileinfo', 'gd', 'mbstring', 'openssl', 'pdo_mysql', 'mysqli', 'zip', 'exif'] as $ext) {
        if (is_file($root . '/ext/php_' . $ext . '.dll') || PHP_OS_FAMILY !== 'Windows') {
            $phpIni .= "extension = " . $ext . "\n";
        }
    }
    $phpIni .= "date.timezone = Asia/Shanghai\n"
        . "cgi.force_redirect = 0\n"
        . "cgi.fix_pathinfo = 1\n"
        . "fastcgi.impersonate = 1\n"
        . "[Session]\n"
        . "session.save_path = \"" . str_replace('\\', '/', $root) . "/tmp\"\n";
    file_put_contents($root . '/php.ini', $phpIni);
}

function runtime_configure_redis(string $root): void
{
    @mkdir($root . '/logs', 0777, true);
    @mkdir($root . '/data', 0777, true);
    $port = runtime_port('redis');
    $conf = "bind 127.0.0.1\n"
        . "protected-mode yes\n"
        . "port " . $port . "\n"
        . "timeout 0\n"
        . "tcp-keepalive 300\n"
        . "loglevel notice\n"
        . "logfile \"" . str_replace('\\', '/', $root) . "/logs/redis.log\"\n"
        . "databases 16\n"
        . "dir \"" . str_replace('\\', '/', $root) . "/data\"\n"
        . "appendonly no\n";
    file_put_contents($root . '/tarsk-redis.conf', $conf);
}

/* ------------------------------- 启停控制 ------------------------------- */

function runtime_start(string $key): array
{
    if (!runtime_installed($key)) {
        return ['ok' => false, 'message' => '尚未安装'];
    }
    if (runtime_state($key)['running']) {
        return ['ok' => true, 'message' => '已在运行'];
    }
    $port = runtime_port($key);
    if (!runtime_port_free($port)) {
        return ['ok' => false, 'message' => '端口 ' . $port . ' 已被其他进程占用，请先在设置中调整端口'];
    }

    if (str_starts_with($key, 'php')) {
        return runtime_php_start($key);
    }
    switch ($key) {
        case 'nginx':
            return runtime_nginx_start();
        case 'redis':
            return runtime_redis_start();
    }
    return ['ok' => false, 'message' => '不支持的应用'];
}

function runtime_stop(string $key): array
{
    if (str_starts_with($key, 'php')) {
        return runtime_stop_by_pid($key, runtime_package($key)['name'] ?? 'PHP');
    }
    switch ($key) {
        case 'nginx':
            return runtime_nginx_stop();
        case 'redis':
            return runtime_redis_stop();
    }
    return ['ok' => false, 'message' => '不支持的应用'];
}

function runtime_restart(string $key): array
{
    $stop = runtime_stop($key);
    usleep(600000);
    return runtime_start($key);
}

function runtime_port_free(int $port): bool
{
    if ($port <= 0) {
        return true;
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        $res = run_cmd('ss -ltn 2>/dev/null | grep -c ":' . $port . ' "', 8);
        return trim($res['output']) === '0';
    }
    $res = ps_run(
        "\$c = Get-NetTCPConnection -LocalPort " . $port . " -State Listen -ErrorAction SilentlyContinue\n"
        . "if (\$c) { 'BUSY' } else { 'FREE' }",
        12
    );
    return stripos($res['output'], 'FREE') !== false;
}

function runtime_nginx_start(): array
{
    $root = managed_nginx_root();
    if ($root === null) {
        return ['ok' => false, 'message' => '未找到 Nginx 可执行文件'];
    }
    $bin = $root . '/nginx.exe';
    $test = ps_run('& \'' . $bin . '\' -p \'' . $root . '/\' -c conf/nginx.conf -t 2>&1', 20);
    if ($test['code'] !== 0) {
        return ['ok' => false, 'message' => 'Nginx 配置检测失败：' . ($test['output'] ?: $test['error'])];
    }

    $script = "\$p = Start-Process -FilePath '" . $bin . "' -ArgumentList @('-p','" . $root . "/','-c','conf/nginx.conf')"
        . " -WorkingDirectory '" . $root . "' -WindowStyle Hidden -PassThru\n"
        . "Start-Sleep -Milliseconds 900\n"
        . "\$p.Id";
    $result = ps_run($script, 40);
    $pid = 0;
    foreach (preg_split('/\r?\n/', trim($result['output'])) as $line) {
        if (ctype_digit(trim($line))) {
            $pid = (int)trim($line);
        }
    }
    if (is_file($root . '/logs/nginx.pid')) {
        $filePid = (int)trim((string)file_get_contents($root . '/logs/nginx.pid'));
        if ($filePid > 0 && runtime_pid_alive($filePid)) {
            $pid = $filePid;
        }
    }
    if ($pid <= 0) {
        return ['ok' => false, 'message' => 'Nginx 启动失败：' . ($result['error'] ?: $result['output'] ?: '未获得进程号')];
    }

    runtime_save_db('nginx', ['status' => 'running', 'pid' => $pid, 'port' => runtime_port('nginx'), 'version' => runtime_version('nginx')]);
    usleep(500000);
    if (!runtime_pid_alive($pid)) {
        runtime_save_db('nginx', ['status' => 'stopped', 'pid' => 0]);
        return ['ok' => false, 'message' => 'Nginx 启动后立即退出，请查看错误日志'];
    }
    return ['ok' => true, 'message' => 'Nginx 已启动，监听端口 ' . runtime_port('nginx')];
}

function runtime_nginx_stop(): array
{
    $root = managed_nginx_root();
    if ($root === null) {
        return ['ok' => false, 'message' => '未安装 Nginx'];
    }
    ps_run('& \'' . $root . '/nginx.exe\' -p \'' . $root . '/\' -c conf/nginx.conf -s stop 2>&1', 25);
    $row = runtime_db_row('nginx');
    $pid = (int)($row['pid'] ?? 0);
    usleep(600000);
    if ($pid > 0 && runtime_pid_alive($pid)) {
        ps_run('Stop-Process -Id ' . $pid . ' -Force -ErrorAction SilentlyContinue', 15);
    }
    runtime_save_db('nginx', ['status' => 'stopped', 'pid' => 0]);
    return ['ok' => true, 'message' => 'Nginx 已停止'];
}

function runtime_nginx_reload(): bool
{
    $root = managed_nginx_root();
    if ($root === null || !is_file($root . '/logs/nginx.pid')) {
        return false;
    }
    $res = ps_run('& \'' . $root . '/nginx.exe\' -p \'' . $root . '/\' -c conf/nginx.conf -s reload 2>&1', 20);
    return $res['code'] === 0;
}

/**
 * 重新打开日志文件（日志切割后调用，避免 Nginx 继续写已重命名的句柄）
 */
function runtime_nginx_reopen(): bool
{
    $root = managed_nginx_root();
    if ($root === null || !is_file($root . '/logs/nginx.pid')) {
        return false;
    }
    $res = ps_run('& \'' . $root . '/nginx.exe\' -p \'' . $root . '/\' -c conf/nginx.conf -s reopen 2>&1', 20);
    return $res['code'] === 0;
}

function runtime_php_start(string $key = 'php'): array
{
    $root = runtime_root($key);
    if (!is_file($root . '/php-cgi.exe')) {
        return ['ok' => false, 'message' => '未找到 php-cgi.exe'];
    }
    $port = runtime_port($key);
    @mkdir($root . '/logs', 0777, true);
    if (PHP_OS_FAMILY === 'Windows') {
        // 使用 Start-Process 启动，进程与面板/父进程解耦，面板重启后 PHP-CGI 仍存活；
        // 不要加输出重定向：目标日志被上一次进程占用会让 Start-Process 永久阻塞。
        $script = sprintf(
            "\$p = Start-Process -FilePath '%s' -ArgumentList @('-b','127.0.0.1:%d','-c','%s/php.ini')"
            . " -WorkingDirectory '%s' -WindowStyle Hidden -PassThru\nStart-Sleep -Milliseconds 900\n\$p.Id",
            $root . '/php-cgi.exe',
            $port,
            $root,
            $root
        );
        $result = ps_run($script, 40);
        $pid = 0;
        foreach (preg_split('/\r?\n/', trim($result['output'])) as $line) {
            if (ctype_digit(trim($line))) {
                $pid = (int)trim($line);
            }
        }
        usleep(400000);
        if ($pid > 0 && runtime_pid_alive($pid)) {
            $name = runtime_package($key)['name'] ?? 'PHP';
            runtime_save_db($key, ['status' => 'running', 'pid' => $pid, 'port' => $port, 'version' => runtime_version($key)]);
            return ['ok' => true, 'message' => $name . ' 已启动，监听 127.0.0.1:' . $port];
        }
        $found = runtime_find_pid_by_port($port);
        if ($found > 0) {
            $name = runtime_package($key)['name'] ?? 'PHP';
            runtime_save_db($key, ['status' => 'running', 'pid' => $found, 'port' => $port, 'version' => runtime_version($key)]);
            return ['ok' => true, 'message' => $name . ' 已启动，监听 127.0.0.1:' . $port];
        }
        $tail = runtime_log_tail($key, 10);
        return ['ok' => false, 'message' => 'PHP-CGI 启动失败' . ($tail !== '' ? '：' . $tail : '，请检查 php.ini 与 VC 运行库')];
    }

    $cmd = escapeshellarg($root . '/php-cgi.exe') . ' -b 127.0.0.1:' . $port
        . ' -c ' . escapeshellarg($root . '/php.ini');
    pclose(popen($cmd . ' > /dev/null 2>&1 &', 'r'));
    usleep(1200000);
    $pid = runtime_find_pid_by_port($port);
    if ($pid <= 0) {
        return ['ok' => false, 'message' => 'PHP-CGI 启动失败：端口 ' . $port . ' 未开始监听'];
    }
    $name = runtime_package($key)['name'] ?? 'PHP';
    runtime_save_db($key, ['status' => 'running', 'pid' => $pid, 'port' => $port, 'version' => runtime_version($key)]);
    return ['ok' => true, 'message' => $name . ' 已启动，监听 127.0.0.1:' . $port];
}

function runtime_redis_start(): array
{
    $root = managed_redis_root();
    if ($root === null) {
        return ['ok' => false, 'message' => '未找到 redis-server.exe'];
    }
    @mkdir($root . '/logs', 0777, true);
    $script = sprintf(
        "\$p = Start-Process -FilePath '%s' -ArgumentList '%s/tarsk-redis.conf' -WorkingDirectory '%s' -WindowStyle Hidden -PassThru\nStart-Sleep -Milliseconds 900\n\$p.Id",
        $root . '/redis-server.exe',
        $root,
        $root
    );
    $result = ps_run($script, 40);
    $pid = 0;
    foreach (preg_split('/\r?\n/', trim($result['output'])) as $line) {
        if (ctype_digit(trim($line))) {
            $pid = (int)trim($line);
        }
    }
    if ($pid <= 0 || !runtime_pid_alive($pid)) {
        return ['ok' => false, 'message' => 'Redis 启动失败：' . ($result['error'] ?: $result['output'] ?: '未获得进程号')];
    }
    runtime_save_db('redis', ['status' => 'running', 'pid' => $pid, 'port' => runtime_port('redis')]);
    return ['ok' => true, 'message' => 'Redis 已启动，监听 127.0.0.1:' . runtime_port('redis')];
}

function runtime_redis_stop(): array
{
    $root = managed_redis_root();
    if ($root === null) {
        return ['ok' => false, 'message' => '未安装 Redis'];
    }
    $port = runtime_port('redis');
    $client = $root . '/redis-cli.exe';
    if (is_file($client)) {
        ps_run('& \'' . $client . '\' -h 127.0.0.1 -p ' . $port . ' shutdown nosave 2>&1', 20);
    }
    usleep(500000);
    $row = runtime_db_row('redis');
    $pid = (int)($row['pid'] ?? 0);
    if ($pid > 0 && runtime_pid_alive($pid)) {
        ps_run('Stop-Process -Id ' . $pid . ' -Force -ErrorAction SilentlyContinue', 15);
    }
    runtime_save_db('redis', ['status' => 'stopped', 'pid' => 0]);
    return ['ok' => true, 'message' => 'Redis 已停止'];
}

function runtime_stop_by_pid(string $key, string $label): array
{
    $row = runtime_db_row($key);
    $pid = (int)($row['pid'] ?? 0);
    if ($pid <= 0 || !runtime_pid_alive($pid)) {
        runtime_save_db($key, ['status' => 'stopped', 'pid' => 0]);
        return ['ok' => true, 'message' => $label . ' 未在运行'];
    }
    ps_run('Stop-Process -Id ' . $pid . ' -Force -ErrorAction SilentlyContinue', 15);
    usleep(400000);
    runtime_save_db($key, ['status' => 'stopped', 'pid' => 0]);
    return ['ok' => true, 'message' => $label . ' 已停止'];
}

/* -------------------------------- 卸载 --------------------------------- */

function runtime_uninstall(string $key): array
{
    $pkg = runtime_package($key);
    if (!$pkg) {
        return ['ok' => false, 'message' => '未知的软件包'];
    }
    if (!runtime_installed($key)) {
        return ['ok' => false, 'message' => '尚未安装'];
    }
    runtime_stop($key);
    usleep(400000);

    $root = runtime_root($key);
    if (!delete_path($root)) {
        return ['ok' => false, 'message' => '目录删除失败，请检查文件占用：' . $root];
    }

    execute('DELETE FROM ' . t('soft') . ' WHERE app_key = ? AND managed = 1', [$key]);

    if (function_exists('sync_all_site_vhosts')) {
        sync_all_site_vhosts();
    }
    return ['ok' => true, 'message' => $pkg['name'] . ' 已卸载'];
}

function runtime_open_dir(string $key): array
{
    if (!runtime_installed($key)) {
        return ['ok' => false, 'message' => '尚未安装'];
    }
    $root = runtime_root($key);
    if (PHP_OS_FAMILY === 'Windows') {
        ps_run("Start-Process -FilePath '" . $root . "'", 15);
    } else {
        run_cmd('xdg-open ' . escapeshellarg($root) . ' >/dev/null 2>&1 &', 8);
    }
    return ['ok' => true, 'message' => '已尝试打开目录：' . $root];
}
