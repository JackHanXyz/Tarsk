<?php
declare(strict_types=1);

/**
 * 系统层工具：时区、磁盘、swap、SSH、常用优化。
 * Windows 与 Linux 双路径，能力在哪个平台不存在就明确说"不支持"，不假装成功。
 */

function sys_timezone_windows_map(): array
{
    return [
        'Asia/Shanghai' => 'China Standard Time',
        'Asia/Hong_Kong' => 'China Standard Time',
        'Asia/Taipei' => 'Taipei Standard Time',
        'Asia/Tokyo' => 'Tokyo Standard Time',
        'Asia/Seoul' => 'Korea Standard Time',
        'Asia/Singapore' => 'Singapore Standard Time',
        'UTC' => 'UTC',
        'Europe/London' => 'GMT Standard Time',
        'Europe/Berlin' => 'W. Europe Standard Time',
        'America/New_York' => 'Eastern Standard Time',
        'America/Los_Angeles' => 'Pacific Standard Time',
    ];
}

function sys_timezone_get(): array
{
    if (PHP_OS_FAMILY === 'Windows') {
        $res = run_cmd('tzutil /g', 10);
        $winId = trim((string)$res['output']);
        $phpTz = array_search($winId, sys_timezone_windows_map(), true) ?: $winId;
        return ['ok' => $res['code'] === 0, 'current' => $phpTz, 'raw' => $winId, 'php' => date_default_timezone_get()];
    }
    $res = run_cmd('bash -lc "timedatectl show -p Timezone --value 2>/dev/null || cat /etc/timezone 2>/dev/null"', 10);
    return ['ok' => $res['code'] === 0, 'current' => trim((string)$res['output']) ?: 'unknown', 'raw' => '', 'php' => date_default_timezone_get()];
}

function sys_timezone_set(string $tz): array
{
    $tz = trim($tz);
    if ($tz === '') {
        return ['ok' => false, 'message' => '时区不能为空'];
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $winId = sys_timezone_windows_map()[$tz] ?? null;
        if ($winId === null) {
            return ['ok' => false, 'message' => '暂不支持该时区（Windows 端内置常见时区映射），可选：' . implode('、', array_keys(sys_timezone_windows_map()))];
        }
        $res = run_cmd('tzutil /s ' . escapeshellarg($winId), 15);
        if ($res['code'] !== 0) {
            return ['ok' => false, 'message' => '设置失败（需要管理员权限）：' . mb_substr(trim($res['error']), 0, 150)];
        }
        log_action('设置系统时区', $tz);
        return ['ok' => true, 'message' => '系统时区已设为 ' . $tz];
    }
    $res = run_cmd('bash -lc ' . escapeshellarg('timedatectl set-timezone ' . escapeshellarg($tz)), 20);
    return $res['code'] === 0
        ? ['ok' => true, 'message' => '系统时区已设为 ' . $tz]
        : ['ok' => false, 'message' => '设置失败（需要 root）：' . mb_substr(trim($res['error']), 0, 150)];
}

function sys_disks(): array
{
    $disks = [];
    if (PHP_OS_FAMILY === 'Windows') {
        $script = "Get-CimInstance Win32_LogicalDisk -Filter \"DriveType=3\" | ForEach-Object {\n"
            . '  "{0}|{1}|{2}|{3}" -f $_.DeviceID, $_.VolumeName, $_.Size, $_.FreeSpace' . "\n"
            . '}';
        $res = ps_run($script, 25);
        foreach (preg_split('/\r?\n/', trim((string)$res['output'])) ?: [] as $line) {
            $parts = explode('|', trim($line));
            if (count($parts) !== 4 || (int)$parts[2] === 0) {
                continue;
            }
            $disks[] = [
                'mount' => rtrim($parts[0], '\\'),
                'label' => $parts[1],
                'total' => (int)$parts[2],
                'free' => (int)$parts[3],
            ];
        }
        return $disks;
    }
    $res = run_cmd('bash -lc "df -kP -x tmpfs -x devtmpfs | tail -n +2"', 15);
    foreach (preg_split('/\r?\n/', trim((string)$res['output'])) ?: [] as $line) {
        $cols = preg_split('/\s+/', trim($line)) ?: [];
        if (count($cols) < 6 || !str_starts_with($cols[0], '/')) {
            continue;
        }
        $disks[] = [
            'mount' => $cols[5],
            'label' => $cols[0],
            'total' => (int)$cols[1] * 1024,
            'free' => (int)$cols[3] * 1024,
        ];
    }
    return $disks;
}

function sys_swap_status(): array
{
    if (PHP_OS_FAMILY === 'Windows') {
        $script = "Get-CimInstance Win32_PageFileUsage | ForEach-Object {\n"
            . '  "{0}|{1}|{2}" -f $_.Name, ($_.AllocatedBaseSize * 1MB), ($_.CurrentUsage * 1MB)' . "\n"
            . '}';
        $res = ps_run($script, 20);
        $files = [];
        foreach (preg_split('/\r?\n/', trim((string)$res['output'])) ?: [] as $line) {
            $parts = explode('|', trim($line));
            if (count($parts) === 3) {
                $files[] = ['name' => $parts[0], 'size' => (int)$parts[1], 'used' => (int)$parts[2]];
            }
        }
        return ['platform' => 'windows', 'note' => 'Windows 使用页面文件（pagefile），由系统自动管理，无需手动配置 swap。', 'files' => $files, 'swappiness' => null];
    }
    $res = run_cmd('bash -lc "cat /proc/swaps | tail -n +2; echo ---; cat /proc/sys/vm/swappiness 2>/dev/null || echo -"', 15);
    [$swaps, , $swapness] = array_pad(explode('---', (string)$res['output']), 3, '');
    $files = [];
    foreach (preg_split('/\r?\n/', trim($swaps)) ?: [] as $line) {
        $cols = preg_split('/\s+/', trim($line)) ?: [];
        if (count($cols) >= 5) {
            $files[] = ['name' => $cols[0], 'size' => (int)$cols[3] * 1024, 'used' => (int)$cols[4] * 1024];
        }
    }
    return [
        'platform' => 'linux',
        'note' => 'swap 不足时可在 root 下执行：fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile，并写入 /etc/fstab。',
        'files' => $files,
        'swappiness' => trim($swapness) !== '' ? (int)trim($swapness) : null,
    ];
}

function sys_swap_set_swappiness(int $value): array
{
    $value = max(0, min(100, $value));
    if (PHP_OS_FAMILY === 'Windows') {
        return ['ok' => false, 'message' => 'Windows 不适用'];
    }
    $cmd = 'sysctl -w vm.swappiness=' . $value
        . ' && echo "vm.swappiness=' . $value . '" > /etc/sysctl.d/99-tarsk-swap.conf';
    $res = run_cmd('bash -lc ' . escapeshellarg($cmd), 20);
    return $res['code'] === 0
        ? ['ok' => true, 'message' => 'swappiness 已设为 ' . $value . '（已持久化到 /etc/sysctl.d/99-tarsk-swap.conf）']
        : ['ok' => false, 'message' => '设置失败（需要 root）：' . mb_substr(trim($res['error']), 0, 150)];
}

function sys_ssh_status(): array
{
    if (PHP_OS_FAMILY === 'Windows') {
        $res = ps_run('(Get-Service sshd -ErrorAction SilentlyContinue) | ForEach-Object { "{0}|{1}" -f $_.Status, $_.StartType }', 15);
        $line = trim((string)$res['output']);
        if ($line === '') {
            return ['platform' => 'windows', 'installed' => false, 'running' => false, 'port' => null, 'note' => '未安装 OpenSSH Server，可在「设置 → 应用 → 可选功能」中安装。'];
        }
        [$status, $startType] = array_pad(explode('|', $line), 2, '');
        return ['platform' => 'windows', 'installed' => true, 'running' => $status === 'Running', 'port' => 22, 'start_type' => $startType, 'note' => 'Windows OpenSSH 由「服务 sshd」管理。'];
    }
    $res = run_cmd('bash -lc "systemctl is-active sshd 2>/dev/null || systemctl is-active ssh 2>/dev/null; echo ---; grep -E ^Port /etc/ssh/sshd_config 2>/dev/null | head -1"', 15);
    [$state, , $portLine] = array_pad(explode('---', (string)$res['output']), 3, '');
    $port = null;
    if (preg_match('/Port\s+(\d+)/', $portLine, $m)) {
        $port = (int)$m[1];
    }
    return [
        'platform' => 'linux',
        'installed' => trim($state) !== '',
        'running' => trim($state) === 'active',
        'port' => $port ?? 22,
        'note' => '修改端口/禁用密码登录请编辑 /etc/ssh/sshd_config 后 systemctl reload sshd。',
    ];
}

function sys_optimize_actions(): array
{
    if (PHP_OS_FAMILY === 'Windows') {
        return [
            ['id' => 'power_high', 'label' => '切换高性能电源计划', 'desc' => '避免节能策略降频影响 Web 响应', 'cmd' => 'powercfg /setactive 8c5e7fda-e8bf-4a96-9a85-a6e23a8c635c'],
            ['id' => 'hibernate_off', 'label' => '关闭休眠（释放 hiberfil.sys）', 'desc' => '台式机/常开服务器建议关闭，可释放数 GB 磁盘', 'cmd' => 'powercfg /h off'],
        ];
    }
    return [
        ['id' => 'swappiness10', 'label' => 'swappiness 调为 10', 'desc' => '大内存服务器减少 swap 倾向，数据库机器建议 1-10', 'cmd' => 'sysctl:10'],
    ];
}

function sys_optimize_apply(string $id): array
{
    foreach (sys_optimize_actions() as $action) {
        if ($action['id'] !== $id) {
            continue;
        }
        if ($action['cmd'] === 'sysctl:10') {
            return sys_swap_set_swappiness(10);
        }
        $res = run_cmd($action['cmd'], 30);
        if ($res['code'] === 0) {
            log_action('系统优化', $action['label']);
            return ['ok' => true, 'message' => $action['label'] . '：已完成'];
        }
        return ['ok' => false, 'message' => '执行失败（需要管理员权限）：' . mb_substr(trim($res['error'] ?: $res['output']), 0, 150)];
    }
    return ['ok' => false, 'message' => '未知优化项'];
}
