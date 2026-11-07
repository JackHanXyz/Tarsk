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

function runtime_catalog(): array
{
    return [
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
        'php' => [
            'name' => 'PHP',
            'version' => '8.3 最新稳定版',
            'url' => 'https://windows.php.net/downloads/releases/latest/php-8.3-nts-Win32-vs16-x64-latest.zip',
            'size' => '约 34 MB',
            'port_setting' => 'php_port',
            'default_port' => 9000,
            'icon' => 'terminal',
            'desc' => 'PHP-CGI 运行环境，为站点提供 PHP 解析；已预启用 PDO_MySQL、OpenSSL、mbstring 等常用扩展。',
        ],
        'redis' => [
            'name' => 'Redis',
            'version' => '5.0.14.1',
            'url' => 'https://github.com/tporadowski/redis/releases/download/v5.0.14.1/Redis-x64-5.0.14.1.zip',
            'size' => '约 12 MB',
            'port_setting' => 'redis_port',
            'default_port' => 6380,
            'icon' => 'zap',
            'desc' => '内存键值缓存，用于会话、队列与对象缓存。默认监听 127.0.0.1:6380。',
        ],
    ];
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
    return [
        'nginx' => 'nginx.exe',
        'php' => 'php-cgi.exe',
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
    return [
        'nginx' => $root . '/logs/error.log',
        'php' => $root . '/logs/php_error.log',
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
    switch ($key) {
        case 'nginx':
            runtime_configure_nginx($root);
            $version = runtime_version('nginx') ?: $pkg['version'];
            break;
        case 'php':
            runtime_configure_php($root);
            $version = runtime_version('php') ?: $pkg['version'];
            break;
        case 'redis':
            runtime_configure_redis($root);
            $version = runtime_version('redis') ?: $pkg['version'];
            break;
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
    $commands = [
        'nginx' => escapeshellarg($root . '/nginx.exe') . ' -v 2>&1',
        'php' => escapeshellarg($root . '/php.exe') . ' -v 2>&1',
        'redis' => escapeshellarg($root . '/redis-server.exe') . ' --version 2>&1',
    ];
    $command = $commands[$key] ?? '';
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
        . "extension_dir = \"ext\"\n"
        . "extension = curl\n"
        . "extension = fileinfo\n"
        . "extension = gd\n"
        . "extension = mbstring\n"
        . "extension = openssl\n"
        . "extension = pdo_mysql\n"
        . "extension = mysqli\n"
        . "extension = zip\n"
        . "extension = exif\n"
        . "date.timezone = Asia/Shanghai\n"
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

    switch ($key) {
        case 'nginx':
            return runtime_nginx_start();
        case 'php':
            return runtime_php_start();
        case 'redis':
            return runtime_redis_start();
    }
    return ['ok' => false, 'message' => '不支持的应用'];
}

function runtime_stop(string $key): array
{
    switch ($key) {
        case 'nginx':
            return runtime_nginx_stop();
        case 'php':
            return runtime_stop_by_pid($key, 'php');
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

function runtime_php_start(): array
{
    $root = managed_php_root();
    if ($root === null) {
        return ['ok' => false, 'message' => '未找到 php-cgi.exe'];
    }
    $port = runtime_port('php');
    @mkdir($root . '/logs', 0777, true);
    $script = sprintf(
        "\$p = Start-Process -FilePath '%s' -ArgumentList @('-b','127.0.0.1:%d','-c','%s/php.ini')"
        . " -WorkingDirectory '%s' -WindowStyle Hidden"
        . " -RedirectStandardOutput '%s/logs/php.out.log'"
        . " -RedirectStandardError '%s/logs/php.err.log' -PassThru\n"
        . "Start-Sleep -Milliseconds 900\n\$p.Id",
        $root . '/php-cgi.exe',
        $port,
        $root,
        $root,
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
    if ($pid <= 0) {
        return ['ok' => false, 'message' => 'PHP-CGI 启动失败：' . ($result['error'] ?: $result['output'] ?: '未获得进程号')];
    }
    usleep(700000);
    if (!runtime_pid_alive($pid)) {
        $tail = runtime_log_tail('php', 20);
        return ['ok' => false, 'message' => 'PHP-CGI 启动后立即退出：' . ($tail ?: '请检查 php.ini 与 VC 运行库')];
    }
    runtime_save_db('php', ['status' => 'running', 'pid' => $pid, 'port' => $port, 'version' => runtime_version('php')]);
    return ['ok' => true, 'message' => 'PHP-CGI 已启动，监听 127.0.0.1:' . $port];
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
