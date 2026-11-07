<?php
declare(strict_types=1);

/**
 * Tarsk 系统适配层：跨平台命令执行、资源监控、服务与软件探测
 */

function run_cmd(string $command, int $timeout = 25): array
{
    if (!function_exists('proc_open')) {
        return ['ok' => false, 'code' => -1, 'output' => '', 'error' => 'proc_open 被禁用'];
    }

    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        return ['ok' => false, 'code' => -1, 'output' => '', 'error' => '无法启动进程'];
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output = '';
    $error = '';
    $start = microtime(true);
    $timedOut = false;
    $exitCode = 0;

    while (true) {
        $status = proc_get_status($process);
        $output .= (string)stream_get_contents($pipes[1]);
        $error .= (string)stream_get_contents($pipes[2]);

        if (!$status['running']) {
            $exitCode = (int)$status['exitcode'];
            break;
        }
        if (microtime(true) - $start > $timeout) {
            proc_terminate($process);
            $timedOut = true;
            break;
        }
        usleep(20000);
    }

    $output .= (string)stream_get_contents($pipes[1]);
    $error .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closed = proc_close($process);
    if (!$timedOut && $exitCode === 0 && $closed > 0) {
        $exitCode = $closed;
    }

    return [
        'ok' => !$timedOut,
        'code' => $timedOut ? -2 : $exitCode,
        'output' => trim($output),
        'error' => $timedOut ? '命令超时' : trim($error),
    ];
}

function ps_run(string $script, int $timeout = 30): array
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return ['ok' => false, 'code' => -1, 'output' => '', 'error' => '仅 Windows 可用'];
    }
    $file = storage_path('tmp/ps_' . md5($script) . '.ps1');
    if (!is_file($file)) {
        @file_put_contents($file, "\xEF\xBB\xBF" . $script);
    }
    $command = 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File '
        . escapeshellarg($file);
    return run_cmd($command, $timeout);
}

function decode_json_loose(string $text): ?array
{
    $text = trim($text);
    if ($text === '') {
        return null;
    }
    $start = strpos($text, '{');
    $startArr = strpos($text, '[');
    if ($startArr !== false && ($start === false || $startArr < $start)) {
        $start = $startArr;
    }
    if ($start === false) {
        return null;
    }
    $candidate = substr($text, $start);
    $decoded = json_decode($candidate, true);
    if (!is_array($decoded)) {
        // 有时 PowerShell 会在 JSON 后面追加换行或提示，尝试截断到最后一个括号
        $end = max((int)strrpos($candidate, '}'), (int)strrpos($candidate, ']'));
        if ($end > 0) {
            $decoded = json_decode(substr($candidate, 0, $end + 1), true);
        }
    }
    return is_array($decoded) ? $decoded : null;
}

/* --------------------------------- 缓存 --------------------------------- */

function cache_get(string $key, int $ttl): ?array
{
    $file = storage_path('cache/' . preg_replace('/[^a-z0-9_\-]/i', '_', $key) . '.json');
    if (!is_file($file) || time() - (int)@filemtime($file) > $ttl) {
        return null;
    }
    $data = json_decode((string)@file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function cache_set(string $key, array $value): void
{
    $file = storage_path('cache/' . preg_replace('/[^a-z0-9_\-]/i', '_', $key) . '.json');
    @file_put_contents($file, json_encode($value, JSON_UNESCAPED_UNICODE));
}

function cache_forget(string $key): void
{
    $file = storage_path('cache/' . preg_replace('/[^a-z0-9_\-]/i', '_', $key) . '.json');
    if (is_file($file)) {
        @unlink($file);
    }
}

/* --------------------------------- 指标 --------------------------------- */

function system_metrics(bool $fresh = false): array
{
    if (!$fresh) {
        $cached = cache_get('metrics', 4);
        if ($cached !== null) {
            return $cached;
        }
    }

    $metrics = PHP_OS_FAMILY === 'Windows' ? metrics_windows() : metrics_unix();
    $metrics['os_family'] = PHP_OS_FAMILY;
    $metrics['hostname'] = (string)gethostname();
    $metrics['os_name'] = php_uname('s') . ' ' . php_uname('r');
    $metrics['php_version'] = PHP_VERSION;
    $metrics['time'] = date('H:i:s');

    cache_set('metrics', $metrics);
    return $metrics;
}

function metrics_windows(): array
{
    $script = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue'
$o = Get-CimInstance -ClassName Win32_OperatingSystem
$cpuSamples = @(Get-CimInstance -ClassName Win32_Processor | Select-Object -ExpandProperty LoadPercentage)
$cpu = 0
if ($cpuSamples.Count -gt 0) {
    $cpu = [math]::Round((($cpuSamples | Measure-Object -Average).Average), 1)
}
$memTotal = [int64]$o.TotalVisibleMemorySize * 1024
$memFree = [int64]$o.FreePhysicalMemory * 1024
$drives = @()
$sysDrive = $env:SystemDrive
Get-CimInstance -ClassName Win32_LogicalDisk -Filter 'DriveType=3' | ForEach-Object {
    $drives += @{ name = $_.DeviceID; total = [int64]$_.Size; free = [int64]$_.FreeSpace }
}
$netRx = 0
$netTx = 0
$adapters = Get-NetAdapterStatistics
if ($adapters) {
    $netRx = ($adapters | Measure-Object -Property ReceivedBytes -Sum).Sum
    $netTx = ($adapters | Measure-Object -Property SentBytes -Sum).Sum
}
$uptime = 0
if ($o.LastBootUpTime) {
    $uptime = [int64]((Get-Date) - $o.LastBootUpTime).TotalSeconds
}
$diskTotal = 0
$diskFree = 0
foreach ($d in $drives) {
    if ($d.name -eq $sysDrive) { $diskTotal = $d.total; $diskFree = $d.free }
}
if ($diskTotal -eq 0 -and $drives.Count -gt 0) {
    $diskTotal = $drives[0].total
    $diskFree = $drives[0].free
}
$result = @{
    cpu = [double]$cpu
    load1 = 0
    mem_total = [int64]$memTotal
    mem_used = [int64]($memTotal - $memFree)
    disk_total = [int64]$diskTotal
    disk_used = [int64]($diskTotal - $diskFree)
    net_rx = [int64]$netRx
    net_tx = [int64]$netTx
    uptime = [int64]$uptime
    drives = $drives
}
$result | ConvertTo-Json -Compress -Depth 4
PS;

    $result = ps_run($script, 20);
    $data = decode_json_loose($result['output']);
    if (!is_array($data)) {
        return [
            'cpu' => 0, 'load1' => 0, 'mem_total' => 0, 'mem_used' => 0,
            'disk_total' => 0, 'disk_used' => 0, 'net_rx' => 0, 'net_tx' => 0,
            'uptime' => 0, 'drives' => [], 'error' => $result['error'] ?: $result['output'],
        ];
    }
    $drives = $data['drives'] ?? [];
    if (isset($drives['name'])) {
        $drives = [$drives];
    }
    $data['drives'] = array_values($drives);
    return $data;
}

function metrics_unix(): array
{
    $memTotal = 0;
    $memAvailable = 0;
    foreach (file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/MemTotal:\s+(\d+)/', $line, $m)) {
            $memTotal = (int)$m[1] * 1024;
        }
        if (preg_match('/MemAvailable:\s+(\d+)/', $line, $m)) {
            $memAvailable = (int)$m[1] * 1024;
        }
    }

    $cpu = 0.0;
    $statFile = storage_path('cache/cpu_stat.json');
    $prev = is_file($statFile) ? json_decode((string)file_get_contents($statFile), true) : null;
    $statRaw = (string)@file_get_contents('/proc/stat', false, null, 0, 200);
    $firstLine = explode("\n", $statRaw)[0] ?? '';
    $parts = preg_split('/\s+/', trim($firstLine));
    if (count($parts) >= 8) {
        $values = array_map('intval', array_slice($parts, 1, 8));
        $idle = $values[3] + ($values[4] ?? 0);
        $total = array_sum($values);
        if (is_array($prev) && isset($prev['idle'], $prev['total'])) {
            $dIdle = $idle - $prev['idle'];
            $dTotal = $total - $prev['total'];
            if ($dTotal > 0) {
                $cpu = round(max(0, min(100, (1 - $dIdle / $dTotal)) * 100), 1);
            }
        }
        file_put_contents($statFile, json_encode(['idle' => $idle, 'total' => $total]));
    }

    $load = function_exists('sys_getloadavg') ? (sys_getloadavg()[0] ?? 0) : 0;
    $uptime = 0;
    if (is_file('/proc/uptime')) {
        $uptime = (int)((float)explode(' ', (string)file_get_contents('/proc/uptime'))[0]);
    }

    $netRx = 0;
    $netTx = 0;
    foreach (file('/proc/net/dev', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (strpos($line, ':') === false) {
            continue;
        }
        [$iface, $rest] = explode(':', $line, 2);
        if (trim($iface) === 'lo') {
            continue;
        }
        $cols = preg_split('/\s+/', trim($rest));
        $netRx += (int)($cols[0] ?? 0);
        $netTx += (int)($cols[8] ?? 0);
    }

    $diskTotal = (float)@disk_total_space('/');
    $diskFree = (float)@disk_free_space('/');

    return [
        'cpu' => $cpu,
        'load1' => round((float)$load, 2),
        'mem_total' => $memTotal,
        'mem_used' => max(0, $memTotal - $memAvailable),
        'disk_total' => (int)$diskTotal,
        'disk_used' => (int)($diskTotal - $diskFree),
        'net_rx' => $netRx,
        'net_tx' => $netTx,
        'uptime' => $uptime,
        'drives' => [],
    ];
}

/* --------------------------------- 服务 --------------------------------- */

function services_status(bool $fresh = false): array
{
    if (!$fresh) {
        $cached = cache_get('services', 10);
        if ($cached !== null) {
            return $cached;
        }
    }

    $services = [];

    if (PHP_OS_FAMILY === 'Windows') {
        $script = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue'
$nameList = @('mysql','nginx','redis','redis-server','memcached','docker','com.docker.service','sshd')
$list = @()
foreach ($name in $nameList) {
    $svc = Get-Service -Name $name -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($svc) {
        $list += @{ name = $svc.Name; display = $svc.DisplayName; status = [string]$svc.Status }
    }
}
foreach ($p in @('nginx','redis-server')) {
    $proc = Get-Process -Name $p -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($proc -and -not ($list | Where-Object { $_.name -ieq $p })) {
        $list += @{ name = $p; display = $p; status = 'Running' }
    }
}
$list | ConvertTo-Json -Compress -Depth 3
PS;
        $data = decode_json_loose(ps_run($script, 20)['output']);
        if (is_array($data)) {
            if (isset($data['name'])) {
                $data = [$data];
            }
            foreach ($data as $row) {
                $services[] = [
                    'name' => $row['name'] ?? '',
                    'display' => $row['display'] ?? ($row['name'] ?? ''),
                    'running' => stripos((string)($row['status'] ?? ''), 'Running') !== false,
                    'status' => (string)($row['status'] ?? 'Stopped'),
                ];
            }
        }
    } else {
        $map = [
            'MySQL' => 'mysql',
            'Nginx' => 'nginx',
            'Redis' => 'redis-server',
            'Memcached' => 'memcached',
            'Docker' => 'docker',
        ];
        foreach ($map as $display => $unit) {
            $res = run_cmd('systemctl is-active ' . $unit . ' 2>/dev/null');
            $active = trim($res['output']) === 'active';
            $services[] = [
                'name' => $unit,
                'display' => $display,
                'running' => $active,
                'status' => $active ? 'Running' : 'Stopped',
            ];
        }
    }

    cache_set('services', $services);
    return $services;
}

function service_running(string $keyword): bool
{
    foreach (services_status() as $service) {
        if (stripos($service['name'], $keyword) !== false || stripos($service['display'], $keyword) !== false) {
            if ($service['running']) {
                return true;
            }
        }
    }
    return false;
}

/* --------------------------------- 软件 --------------------------------- */

function command_exists(string $command): ?string
{
    $probe = PHP_OS_FAMILY === 'Windows' ? 'where ' . $command : 'command -v ' . $command;
    $result = run_cmd($probe, 10);
    $first = trim(explode("\n", $result['output'])[0] ?? '');
    return ($result['ok'] && $result['code'] === 0 && $first !== '') ? $first : null;
}

function version_of(string $command): string
{
    $result = run_cmd($command . ' 2>&1', 10);
    $text = trim($result['output'] . ' ' . $result['error']);
    if ($text === '') {
        return '';
    }
    if (preg_match('/([0-9]+\.){1,3}[0-9]+/', $text, $m)) {
        return $m[0];
    }
    return mb_substr(trim(explode("\n", $text)[0]), 0, 40);
}

function btsoft_dir(): string
{
    return 'D:/BtSoft';
}

function find_mysql_bin(): string
{
    $configured = (string)config('system.mysql_bin', '');
    if ($configured !== '' && is_dir($configured)) {
        return rtrim($configured, '/\\');
    }
    $candidates = [
        btsoft_dir() . '/mysql/MySQL5.6/bin',
        btsoft_dir() . '/mysql/MySQL5.7/bin',
        btsoft_dir() . '/mysql/MySQL8.0/bin',
    ];
    if (PHP_OS_FAMILY === 'Windows') {
        foreach (glob(btsoft_dir() . '/mysql/*/bin') ?: [] as $dir) {
            array_unshift($candidates, $dir);
        }
    }
    foreach ($candidates as $dir) {
        if (is_dir($dir)) {
            return $dir;
        }
    }
    return '';
}

function find_nginx_bin(): string
{
    $configured = (string)config('system.nginx_bin', '');
    if ($configured !== '' && is_file($configured)) {
        return $configured;
    }
    foreach ([btsoft_dir() . '/nginx/nginx.exe', 'C:/nginx/nginx.exe'] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    return '';
}

function nginx_vhost_dir(): string
{
    $configured = (string)config('system.nginx_conf', '');
    if ($configured !== '') {
        return rtrim($configured, '/\\');
    }
    $candidate = btsoft_dir() . '/nginx/conf/vhost';
    if (is_dir($candidate)) {
        return $candidate;
    }
    $fallback = storage_path('vhost');
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0777, true);
    }
    return $fallback;
}

function php_versions(): array
{
    $versions = [];
    if (PHP_OS_FAMILY === 'Windows') {
        foreach (glob(btsoft_dir() . '/php/*') ?: [] as $dir) {
            $exe = $dir . '/php.exe';
            if (is_file($exe)) {
                $versions[] = [
                    'version' => basename($dir),
                    'path' => $exe,
                ];
            }
        }
    }
    $versions[] = [
        'version' => PHP_VERSION . ' (当前)',
        'path' => PHP_BINARY,
    ];
    return $versions;
}

function soft_catalog(): array
{
    return [
        [
            'key' => 'nginx', 'name' => 'Nginx', 'category' => 'Web 服务',
            'desc' => '高性能反向代理与 Web 服务器，站点运行的基础。',
            'winget' => '', 'url' => 'https://nginx.org/',
            'probe' => function () {
                $bin = find_nginx_bin();
                if ($bin === '') {
                    return ['installed' => false, 'version' => '', 'path' => ''];
                }
                $res = run_cmd(escapeshellarg($bin) . ' -v 2>&1', 10);
                return ['installed' => true, 'version' => version_of('nginx') ?: trim($res['output']), 'path' => $bin];
            },
        ],
        [
            'key' => 'mysql', 'name' => 'MySQL', 'category' => '数据库',
            'desc' => '关系型数据库，为站点提供数据存储。',
            'winget' => 'Oracle.MySQL', 'url' => 'https://dev.mysql.com/downloads/',
            'probe' => function () {
                $bin = find_mysql_bin();
                if ($bin === '') {
                    return ['installed' => false, 'version' => '', 'path' => ''];
                }
                $res = run_cmd(escapeshellarg($bin . '/mysql.exe') . ' --version 2>&1', 10);
                return ['installed' => true, 'version' => version_of('mysql') ?: trim($res['output']), 'path' => $bin];
            },
        ],
        [
            'key' => 'php', 'name' => 'PHP', 'category' => '运行环境',
            'desc' => '站点脚本运行环境，支持多版本共存。',
            'winget' => 'PHP.PHP.8.3', 'url' => 'https://www.php.net/downloads',
            'probe' => function () {
                $versions = php_versions();
                $bt = array_values(array_filter($versions, fn($v) => stripos($v['version'], '当前') === false));
                if ($bt) {
                    return ['installed' => true, 'version' => $bt[0]['version'], 'path' => $bt[0]['path']];
                }
                return ['installed' => true, 'version' => PHP_VERSION, 'path' => PHP_BINARY];
            },
        ],
        [
            'key' => 'redis', 'name' => 'Redis', 'category' => '缓存',
            'desc' => '内存键值缓存，用于会话、队列与对象缓存。',
            'winget' => 'tporadowski.Redis', 'url' => 'https://redis.io/',
            'probe' => function () {
                $candidates = [btsoft_dir() . '/redis/redis-server.exe', btsoft_dir() . '/redis/redis-server'];
                foreach ($candidates as $path) {
                    if (is_file($path)) {
                        return ['installed' => true, 'version' => version_of('redis-server'), 'path' => $path];
                    }
                }
                $bin = command_exists('redis-server');
                return $bin
                    ? ['installed' => true, 'version' => version_of('redis-server'), 'path' => $bin]
                    : ['installed' => false, 'version' => '', 'path' => ''];
            },
        ],
        [
            'key' => 'docker', 'name' => 'Docker', 'category' => '容器',
            'desc' => '容器运行时，一键部署隔离的应用环境。',
            'winget' => 'Docker.DockerDesktop', 'url' => 'https://www.docker.com/products/docker-desktop/',
            'probe' => function () {
                $bin = command_exists('docker');
                return $bin
                    ? ['installed' => true, 'version' => version_of('docker'), 'path' => $bin]
                    : ['installed' => false, 'version' => '', 'path' => ''];
            },
        ],
        [
            'key' => 'node', 'name' => 'Node.js', 'category' => '运行环境',
            'desc' => 'JavaScript 运行时，用于构建与部署前端项目。',
            'winget' => 'OpenJS.NodeJS.LTS', 'url' => 'https://nodejs.org/',
            'probe' => function () {
                $bin = command_exists('node');
                return $bin
                    ? ['installed' => true, 'version' => ltrim(version_of('node'), 'v'), 'path' => $bin]
                    : ['installed' => false, 'version' => '', 'path' => ''];
            },
        ],
        [
            'key' => 'git', 'name' => 'Git', 'category' => '开发工具',
            'desc' => '版本控制工具，用于代码拉取与部署。',
            'winget' => 'Git.Git', 'url' => 'https://git-scm.com/',
            'probe' => function () {
                $bin = command_exists('git');
                return $bin
                    ? ['installed' => true, 'version' => version_of('git'), 'path' => $bin]
                    : ['installed' => false, 'version' => '', 'path' => ''];
            },
        ],
        [
            'key' => 'composer', 'name' => 'Composer', 'category' => '开发工具',
            'desc' => 'PHP 依赖管理工具，用于项目初始化。',
            'winget' => 'Composer.Composer', 'url' => 'https://getcomposer.org/',
            'probe' => function () {
                $bin = command_exists('composer');
                return $bin
                    ? ['installed' => true, 'version' => version_of('composer'), 'path' => $bin]
                    : ['installed' => false, 'version' => '', 'path' => ''];
            },
        ],
        [
            'key' => 'openssl', 'name' => 'OpenSSL', 'category' => '安全工具',
            'desc' => '证书签发与加密工具，配合 SSL 证书管理使用。',
            'winget' => 'ShiningLight.OpenSSL.Light', 'url' => 'https://www.openssl.org/',
            'probe' => function () {
                $bin = command_exists('openssl');
                return $bin
                    ? ['installed' => true, 'version' => version_of('openssl'), 'path' => $bin]
                    : ['installed' => false, 'version' => '', 'path' => ''];
            },
        ],
        [
            'key' => 'python', 'name' => 'Python', 'category' => '运行环境',
            'desc' => '脚本语言运行时，适合自动化与数据处理。',
            'winget' => 'Python.Python.3.12', 'url' => 'https://www.python.org/',
            'probe' => function () {
                $bin = command_exists('python') ?: command_exists('python3');
                return $bin
                    ? ['installed' => true, 'version' => version_of('python'), 'path' => $bin]
                    : ['installed' => false, 'version' => '', 'path' => ''];
            },
        ],
    ];
}

function soft_check(string $key): ?array
{
    foreach (soft_catalog() as $item) {
        if ($item['key'] === $key) {
            return ($item['probe'])();
        }
    }
    return null;
}

/* --------------------------------- 防火墙 --------------------------------- */

function firewall_status(bool $fresh = false): array
{
    if (!$fresh) {
        $cached = cache_get('firewall', 15);
        if ($cached !== null) {
            return $cached;
        }
    }

    $result = ['profiles' => [], 'supported' => false, 'error' => ''];

    if (PHP_OS_FAMILY === 'Windows') {
        $script = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue'
$profiles = Get-NetFirewallProfile | Select-Object Name,Enabled
$profiles | ConvertTo-Json -Compress -Depth 3
PS;
        $data = decode_json_loose(ps_run($script, 20)['output']);
        if (is_array($data)) {
            if (isset($data['Name'])) {
                $data = [$data];
            }
            foreach ($data as $row) {
                $result['profiles'][] = [
                    'name' => (string)($row['Name'] ?? ''),
                    'enabled' => (bool)($row['Enabled'] ?? false),
                ];
            }
            $result['supported'] = count($result['profiles']) > 0;
        }
    } else {
        $res = run_cmd('ufw status 2>/dev/null', 10);
        if ($res['ok'] && trim($res['output']) !== '') {
            $enabled = stripos($res['output'], 'active') !== false;
            $result['profiles'][] = ['name' => 'ufw', 'enabled' => $enabled];
            $result['supported'] = true;
        }
    }

    cache_set('firewall', $result);
    return $result;
}

function firewall_remove(string $name): array
{
    if (PHP_OS_FAMILY === 'Windows') {
        $command = 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command "Remove-NetFirewallRule -DisplayName '
            . escapeshellarg('Tarsk - ' . $name) . ' -ErrorAction SilentlyContinue"';
        $res = run_cmd($command, 25);
        return [
            'ok' => $res['code'] === 0,
            'message' => $res['code'] === 0 ? '系统防火墙规则已移除' : ('移除失败：' . ($res['error'] ?: '请以管理员身份运行')),
        ];
    }
    return ['ok' => true, 'message' => '请在服务器上同步清理 ufw 规则'];
}

function firewall_apply(array $rule): array
{
    if (PHP_OS_FAMILY === 'Windows') {
        $display = 'Tarsk - ' . $rule['name'];
        $remote = trim((string)($rule['remote_ip'] ?? ''));
        $command = sprintf(
            'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command "New-NetFirewallRule -DisplayName %s -Direction %s -Action %s -Protocol %s %s %s -Enabled True"',
            escapeshellarg($display),
            $rule['direction'] === 'outbound' ? 'Outbound' : 'Inbound',
            $rule['action'] === 'block' ? 'Block' : 'Allow',
            strtoupper($rule['protocol']),
            $rule['port'] !== '' ? ('-LocalPort ' . escapeshellarg($rule['port'])) : '',
            ($remote !== '' && $remote !== 'any') ? ('-RemoteAddress ' . escapeshellarg($remote)) : '',
        );
        $res = run_cmd($command, 30);
        return [
            'ok' => $res['code'] === 0,
            'message' => $res['code'] === 0 ? '规则已写入 Windows 防火墙' : ($res['error'] ?: '写入失败，请以管理员身份运行面板'),
        ];
    }

    if ($rule['action'] === 'allow') {
        $res = run_cmd('ufw allow ' . escapeshellarg($rule['port'] . '/' . $rule['protocol']) . ' 2>&1', 20);
    } else {
        $res = run_cmd('ufw deny ' . escapeshellarg($rule['port'] . '/' . $rule['protocol']) . ' 2>&1', 20);
    }
    return [
        'ok' => $res['code'] === 0,
        'message' => $res['code'] === 0 ? '规则已写入 ufw' : ($res['error'] ?: '写入失败'),
    ];
}

/* --------------------------------- Docker --------------------------------- */

function docker_info(): array
{
    $bin = command_exists('docker');
    if (!$bin) {
        return ['installed' => false, 'running' => false, 'version' => '', 'containers' => [], 'images' => [], 'error' => ''];
    }

    $version = version_of('docker');
    $ps = run_cmd('docker ps -a --format "{{.ID}}|{{.Names}}|{{.Image}}|{{.Status}}|{{.State}}" 2>&1', 20);
    $containers = [];
    if ($ps['ok'] && $ps['code'] === 0) {
        foreach (explode("\n", trim($ps['output'])) as $line) {
            if (strpos($line, '|') === false) {
                continue;
            }
            [$id, $name, $image, $status, $state] = array_pad(explode('|', $line), 5, '');
            $containers[] = [
                'id' => $id, 'name' => $name, 'image' => $image,
                'status' => $status, 'state' => $state,
            ];
        }
    }

    $images = [];
    $img = run_cmd('docker images --format "{{.Repository}}|{{.Tag}}|{{.Size}}|{{.ID}}" 2>&1', 20);
    if ($img['ok'] && $img['code'] === 0) {
        foreach (explode("\n", trim($img['output'])) as $line) {
            if (strpos($line, '|') === false) {
                continue;
            }
            [$repo, $tag, $size, $iid] = array_pad(explode('|', $line), 4, '');
            $images[] = ['repo' => $repo, 'tag' => $tag, 'size' => $size, 'id' => $iid];
        }
    }

    $running = false;
    $info = run_cmd('docker info --format "{{.ServerVersion}}" 2>&1', 20);
    if ($info['ok'] && $info['code'] === 0 && trim($info['output']) !== '') {
        $running = true;
    }

    return [
        'installed' => true,
        'running' => $running,
        'version' => $version,
        'containers' => $containers,
        'images' => $images,
        'error' => $running ? '' : trim($ps['error'] ?: $ps['output']),
    ];
}

/* --------------------------------- MySQL --------------------------------- */

function mysql_admin_credentials(): array
{
    $user = (string)setting('mysql_admin_user', '');
    $pass = (string)setting('mysql_admin_pass', '');
    if ($user !== '') {
        return ['user' => $user, 'pass' => $pass];
    }
    $db = config('database');
    return ['user' => $db['user'], 'pass' => $db['pass']];
}

function mysql_connection(): ?PDO
{
    static $pdo = null;
    static $failed = false;
    if ($pdo !== null || $failed) {
        return $pdo;
    }
    $creds = mysql_admin_credentials();
    $db = config('database');
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;charset=%s', $db['host'], $db['port'], $db['charset'] ?? 'utf8mb4'),
            $creds['user'], $creds['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (Throwable $e) {
        $failed = true;
        $pdo = null;
    }
    return $pdo;
}

function mysql_server_info(): array
{
    $info = [
        'connected' => false,
        'version' => '',
        'uptime' => 0,
        'threads' => 0,
        'queries' => 0,
        'error' => '',
        'user' => mysql_admin_credentials()['user'],
    ];
    $pdo = mysql_connection();
    if (!$pdo) {
        $info['error'] = '无法连接 MySQL，请检查账号权限或服务状态';
        return $info;
    }
    try {
        $info['connected'] = true;
        $info['version'] = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        foreach ($pdo->query('SHOW GLOBAL STATUS')->fetchAll() as $row) {
            $name = strtolower((string)$row['Variable_name']);
            if ($name === 'uptime') {
                $info['uptime'] = (int)$row['Value'];
            } elseif ($name === 'threads_connected') {
                $info['threads'] = (int)$row['Value'];
            } elseif ($name === 'queries') {
                $info['queries'] = (int)$row['Value'];
            }
        }
    } catch (Throwable $e) {
        $info['error'] = $e->getMessage();
    }
    return $info;
}

function mysql_databases(): array
{
    $pdo = mysql_connection();
    if (!$pdo) {
        return [];
    }
    $system = ['information_schema', 'mysql', 'performance_schema', 'sys'];
    $rows = [];
    try {
        $databases = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
        $sizes = [];
        try {
            $sizeRows = $pdo->query(
                "SELECT TABLE_SCHEMA AS schema_name, SUM(DATA_LENGTH + INDEX_LENGTH) AS bytes, COUNT(*) AS tables
                 FROM information_schema.TABLES GROUP BY TABLE_SCHEMA"
            )->fetchAll();
            foreach ($sizeRows as $sizeRow) {
                $sizes[$sizeRow['schema_name']] = $sizeRow;
            }
        } catch (Throwable $e) {
        }
        foreach ($databases as $name) {
            if (in_array(strtolower($name), $system, true)) {
                continue;
            }
            $rows[] = [
                'name' => $name,
                'bytes' => (int)($sizes[$name]['bytes'] ?? 0),
                'tables' => (int)($sizes[$name]['tables'] ?? 0),
            ];
        }
    } catch (Throwable $e) {
        return [];
    }
    return $rows;
}

function mysql_tables(string $database): array
{
    $pdo = mysql_connection();
    if (!$pdo) {
        return [];
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME AS table_name, ENGINE AS engine, TABLE_ROWS AS table_rows,
                    DATA_LENGTH AS data_length, INDEX_LENGTH AS index_length, TABLE_COLLATION AS table_collation
             FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME"
        );
        $stmt->execute([$database]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function mysql_create_database(string $name, string $charset = 'utf8mb4'): array
{
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
        return ['ok' => false, 'message' => '数据库名只能包含字母、数字与下划线'];
    }
    $pdo = mysql_connection();
    if (!$pdo) {
        return ['ok' => false, 'message' => '无法连接 MySQL'];
    }
    try {
        $pdo->exec(sprintf('CREATE DATABASE `%s` DEFAULT CHARACTER SET %s', $name, preg_replace('/[^a-z0-9]/i', '', $charset)));
        return ['ok' => true, 'message' => '数据库 ' . $name . ' 已创建'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

function mysql_drop_database(string $name): array
{
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
        return ['ok' => false, 'message' => '数据库名不合法'];
    }
    $pdo = mysql_connection();
    if (!$pdo) {
        return ['ok' => false, 'message' => '无法连接 MySQL'];
    }
    try {
        $pdo->exec(sprintf('DROP DATABASE `%s`', $name));
        return ['ok' => true, 'message' => '数据库 ' . $name . ' 已删除'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

function mysql_dump(string $database, ?string $file = null): array
{
    $bin = find_mysql_bin();
    $dump = $bin . '/mysqldump.exe';
    if (PHP_OS_FAMILY !== 'Windows') {
        $dump = 'mysqldump';
    }
    if (PHP_OS_FAMILY === 'Windows' && !is_file($dump)) {
        return ['ok' => false, 'message' => '未找到 mysqldump，请确认 MySQL 安装路径'];
    }

    $creds = mysql_admin_credentials();
    $db = config('database');
    $file = $file ?: backup_path() . '/db_' . $database . '_' . date('Ymd_His') . '.sql';
    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0777, true);
    }

    $ini = storage_path('tmp/mysql_' . bin2hex(random_bytes(4)) . '.cnf');
    $content = "[client]\nhost=\"" . $db['host'] . "\"\nport=" . $db['port']
        . "\nuser=\"" . $creds['user'] . "\"\npassword=\"" . str_replace('"', '', $creds['pass']) . "\"\n";
    file_put_contents($ini, $content);

    $command = sprintf(
        '%s --defaults-extra-file=%s --single-transaction --routines %s > %s 2>&1',
        escapeshellarg($dump),
        escapeshellarg($ini),
        escapeshellarg($database),
        escapeshellarg($file)
    );
    $res = run_cmd($command, 120);
    @unlink($ini);

    $ok = is_file($file) && filesize($file) > 0;
    return [
        'ok' => $ok,
        'message' => $ok ? '备份完成：' . $file : ('备份失败：' . ($res['error'] ?: $res['output'])),
        'file' => $file,
    ];
}
