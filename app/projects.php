<?php
declare(strict_types=1);

/**
 * 应用进程托管：Node / Python / Java 项目的启动、停止、探活与日志。
 *
 * 设计：
 * - 进程用「分离启动」方式拉起（Windows PowerShell Start-Process，Linux nohup），
 *   面板进程退出不影响业务进程；
 * - stdout/stderr 各自落到 storage/logs/project_<id>.out / .err，页面看日志 = 读文件；
 * - status 每次「实际探活」后回写数据库，杜绝状态与真实进程脱节；
 * - 自启动：Windows 注册 schtasks ONSTART，Linux 输出 @reboot 行由用户确认后加入 crontab。
 */

function project_log_file(int $id, string $which = 'out'): string
{
    return storage_path('logs/project_' . $id . '.' . $which . '.log');
}

function project_binary_candidates(string $kind): array
{
    return match ($kind) {
        'node' => ['node'],
        'python' => PHP_OS_FAMILY === 'Windows' ? ['python', 'py'] : ['python3', 'python'],
        'java' => ['java'],
        default => [],
    };
}

function project_detect_binary(string $kind): string
{
    foreach (project_binary_candidates($kind) as $bin) {
        $found = command_exists($bin);
        if ($found !== null) {
            return $found;
        }
    }
    return '';
}

/**
 * 启动项目。命令按空格拆分：首段为可执行文件，其余为参数。
 */
function project_start(array $row): array
{
    $id = (int)$row['id'];
    $command = trim((string)$row['command']);
    $dir = str_replace('\\', '/', (string)$row['path']);
    if ($command === '') {
        return ['ok' => false, 'message' => '启动命令不能为空'];
    }
    if ($dir === '' || !is_dir($dir)) {
        return ['ok' => false, 'message' => '项目目录不存在：' . $dir];
    }
    if (project_is_alive((int)$row['pid'])) {
        project_mark($id, 'running', (int)$row['pid'], '进程已在运行（PID ' . (int)$row['pid'] . '）');
        return ['ok' => false, 'message' => '进程已在运行（PID ' . (int)$row['pid'] . '），如需重启请先停止'];
    }

    $parts = preg_split('/\s+/', $command) ?: [];
    $bin = array_shift($parts) ?? '';
    $args = $parts;
    $outLog = str_replace('\\', '/', project_log_file($id, 'out'));
    $errLog = str_replace('\\', '/', project_log_file($id, 'err'));

    if (PHP_OS_FAMILY === 'Windows') {
        $resolved = $bin;
        if (!str_contains($bin, '/') && !str_contains($bin, '\\') && !str_ends_with(strtolower($bin), '.exe')) {
            $where = run_cmd('where ' . escapeshellarg($bin), 10);
            $line = trim(explode("\n", trim((string)$where['output']))[0] ?? '');
            if ($line !== '') {
                $resolved = $line;
            }
        }
        $q = static fn(string $v): string => str_replace("'", "''", $v);
        $script = '$ErrorActionPreference = "Stop"' . "\n"
            . 'try {' . "\n"
            . '  $p = Start-Process -FilePath "' . $q($resolved) . '"'
            . ($args ? ' -ArgumentList @(' . implode(',', array_map(static fn($a) => '"' . addslashes($a) . '"', $args)) . ')' : '')
            . ' -WorkingDirectory "' . $q($dir) . '"'
            . ' -WindowStyle Hidden'
            . ' -RedirectStandardOutput "' . $q($outLog) . '"'
            . ' -RedirectStandardError "' . $q($errLog) . '"'
            . ' -PassThru' . "\n"
            . '  Write-Output $p.Id' . "\n"
            . '  exit 0' . "\n"
            . '} catch {' . "\n"
            // 环境变量里同时存在 HTTPS_PROXY 与 https_proxy 等大小写重复键时，
            // .NET Start-Process 会抛“字典中已存在同键”；退回 ShellExecute + 启动器脚本方式，
            // ShellExecute 不构建环境字典（无该 bug），也不继承当前管道（不会挂住输出采集）
            . '  $launcher = Join-Path $env:TEMP ("tarsk_launch_" + [guid]::NewGuid().ToString("N") + ".cmd")' . "\n"
            . '  $redirect = \' 1> "' . $q($outLog) . '" 2> "' . $q($errLog) . '"\'' . "\n"
            . '  $argText = ' . ($args ? '@(' . implode(',', array_map(static fn($a) => "'" . $q($a) . "'", $args)) . ') -join " "' : '""') . "\n"
            . '  $body = "@echo off" + [Environment]::NewLine'
            . ' + "cd /d ""' . $q($dir) . '""" + [Environment]::NewLine'
            . ' + """' . $q($resolved) . '""" + " " + $argText + $redirect' . "\n"
            . '  [IO.File]::WriteAllText($launcher, $body)' . "\n"
            . '  $psi = New-Object System.Diagnostics.ProcessStartInfo' . "\n"
            . '  $psi.FileName = $launcher' . "\n"
            . '  $psi.UseShellExecute = $true' . "\n"
            . '  $psi.WindowStyle = [System.Diagnostics.ProcessWindowStyle]::Hidden' . "\n"
            . '  $outer = [System.Diagnostics.Process]::Start($psi)' . "\n"
            . '  Start-Sleep -Milliseconds 1500' . "\n"
            . '  $exeName = [IO.Path]::GetFileNameWithoutExtension("' . $q($resolved) . '").ToLower()' . "\n"
            . '  $child = Get-CimInstance Win32_Process -Filter "ParentProcessId=$($outer.Id)" |'
            . ' Where-Object { $_.Name.ToLower() -eq ($exeName + \'.exe\') } | Select-Object -First 1' . "\n"
            . '  if (-not $child) {' . "\n"
            . '    $child = Get-CimInstance Win32_Process -Filter "Name=\'$exeName.exe\'" |'
            . ' Where-Object { $_.CommandLine -and $_.CommandLine.Contains($argText) } |'
            . ' Sort-Object CreationDate -Descending | Select-Object -First 1' . "\n"
            . '  }' . "\n"
            . '  Remove-Item $launcher -ErrorAction SilentlyContinue' . "\n"
            . '  if ($child) { Write-Output $child.ProcessId } else { Write-Output 0 }' . "\n"
            . '}';
        $res = ps_run($script, 40);
        $pid = (int)trim((string)$res['output']);
        if ($pid <= 0) {
            project_mark($id, 'failed', 0, '启动失败：' . mb_substr(trim($res['error'] ?: $res['output']), 0, 180));
            return ['ok' => false, 'message' => '启动失败：' . mb_substr(trim($res['error'] ?: $res['output']), 0, 180)];
        }
    } else {
        $full = escapeshellarg($bin) . ($args ? ' ' . implode(' ', array_map('escapeshellarg', $args)) : '');
        $cmd = 'cd ' . escapeshellarg($dir)
            . ' && nohup ' . $full
            . ' > ' . escapeshellarg($outLog) . ' 2> ' . escapeshellarg($errLog)
            . ' & echo $!';
        $res = run_cmd('bash -lc ' . escapeshellarg($cmd), 20);
        $pid = (int)trim((string)$res['output']);
        if ($pid <= 0) {
            project_mark($id, 'failed', 0, '启动失败：' . mb_substr(trim($res['error']), 0, 180));
            return ['ok' => false, 'message' => '启动失败：' . mb_substr(trim($res['error']), 0, 180)];
        }
    }

    sleep(1);
    $alive = project_is_alive($pid);
    project_mark($id, $alive ? 'running' : 'failed', $alive ? $pid : 0, $alive ? '启动成功' : '启动后进程立即退出，请查看错误日志');
    log_action('启动应用', $row['name'] . ' · PID ' . $pid, $alive ? 'info' : 'warning');
    return ['ok' => $alive, 'message' => $alive ? '已启动（PID ' . $pid . '）' : '进程启动后立即退出，请查看错误日志'];
}

function project_stop(array $row): array
{
    $id = (int)$row['id'];
    $pid = (int)$row['pid'];
    if ($pid <= 0) {
        project_mark($id, 'stopped', 0, '已停止');
        return ['ok' => true, 'message' => '进程未在运行'];
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $res = run_cmd('taskkill /PID ' . $pid . ' /T /F', 20);
        $ok = $res['code'] === 0;
    } else {
        $res = run_cmd('bash -lc ' . escapeshellarg('kill ' . $pid . ' 2>/dev/null; sleep 1; kill -9 ' . $pid . ' 2>/dev/null; true'), 15);
        $ok = !project_is_alive($pid);
    }
    project_mark($id, 'stopped', 0, $ok ? '已停止' : '停止命令已发送，但进程可能仍存活');
    log_action('停止应用', $row['name'] . ' · PID ' . $pid, 'info');
    return ['ok' => $ok, 'message' => $ok ? '已停止' : ('停止失败：' . mb_substr(trim($res['error'] ?: $res['output']), 0, 150))];
}

/**
 * 实际探活：PID 对应进程是否存在。
 */
function project_is_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $res = run_cmd('tasklist /FI "PID eq ' . $pid . '" /NH', 15);
        return str_contains((string)$res['output'], (string)$pid);
    }
    $res = run_cmd('bash -lc ' . escapeshellarg('kill -0 ' . $pid . ' 2>/dev/null && echo alive || echo dead'), 10);
    return str_contains((string)$res['output'], 'alive');
}

/**
 * 页面展示前统一同步：以真实进程状态为准回写。
 */
function project_sync_status(array $row): array
{
    $alive = project_is_alive((int)$row['pid']);
    $status = $alive ? 'running' : ((string)$row['status'] === 'failed' ? 'failed' : 'stopped');
    if ($alive !== ((string)$row['status'] === 'running')) {
        execute(
            'UPDATE ' . t('project_apps') . ' SET status = ?, pid = ? WHERE id = ?',
            [$status, $alive ? (int)$row['pid'] : 0, (int)$row['id']]
        );
        $row['status'] = $status;
        $row['pid'] = $alive ? (int)$row['pid'] : 0;
    }
    $row['alive'] = $alive;
    return $row;
}

function project_mark(int $id, string $status, int $pid, string $message): void
{
    execute(
        'UPDATE ' . t('project_apps') . ' SET status = ?, pid = ?, last_log = ?, last_at = ? WHERE id = ?',
        [$status, $pid, mb_substr($message, 0, 500), now(), $id]
    );
}

function project_tail_log(int $id, string $which = 'out', int $lines = 200): string
{
    $file = project_log_file($id, $which);
    if (!is_file($file)) {
        return '';
    }
    $size = (int)@filesize($file);
    $read = min($size, 200000);
    $content = (string)@file_get_contents($file, false, null, max(0, $size - $read), $read);
    $rows = explode("\n", $content);
    if (count($rows) > $lines) {
        $rows = array_slice($rows, -$lines);
    }
    return implode("\n", $rows);
}

/**
 * 自启动：Windows 注册 ONSTART 计划任务；Linux 返回待加入 crontab 的行。
 */
function project_autostart_apply(array $row): array
{
    $id = (int)$row['id'];
    $php = PHP_BINARY;
    $binProject = str_replace('\\', '/', dirname(__DIR__) . '/bin/project.php');
    if (PHP_OS_FAMILY === 'Windows') {
        if (!empty($row['autostart'])) {
            $res = run_cmd(
                'schtasks /Create /TN ' . escapeshellarg('Tarsk_Project_' . $id)
                . ' /TR ' . escapeshellarg('"' . $php . '" "' . $binProject . '" --id=' . $id)
                . ' /SC ONSTART /RU SYSTEM /F',
                30
            );
            return $res['code'] === 0
                ? ['ok' => true, 'message' => '已注册开机自启（Windows 任务计划程序）']
                : ['ok' => false, 'message' => '注册自启失败：' . mb_substr(trim($res['error']), 0, 150)];
        }
        run_cmd('schtasks /Delete /TN ' . escapeshellarg('Tarsk_Project_' . $id) . ' /F', 20);
        return ['ok' => true, 'message' => '已移除开机自启'];
    }
    return ['ok' => true, 'message' => (!empty($row['autostart']) ? '请将下行加入 root crontab：' : 'Linux 自启请手动移除 crontab 中对应行：')
        . "\n@reboot " . $php . ' ' . $binProject . ' --id=' . $id];
}

function project_validate(array $input): array
{
    $name = trim((string)($input['name'] ?? ''));
    $kind = (string)($input['kind'] ?? 'node');
    $path = rtrim(str_replace('\\', '/', trim((string)($input['path'] ?? ''))), '/');
    $command = trim((string)($input['command'] ?? ''));
    $port = max(0, (int)($input['port'] ?? 0));

    if ($name === '' || !preg_match('/^[\w\x{4e00}-\x{9fa5}.-]{1,60}$/u', $name)) {
        return ['ok' => false, 'message' => '项目名限 1-60 位字母/数字/汉字/中划线'];
    }
    if (!in_array($kind, ['node', 'python', 'java'], true)) {
        return ['ok' => false, 'message' => '应用类型不支持'];
    }
    if ($path === '' || !is_dir($path)) {
        return ['ok' => false, 'message' => '项目目录不存在：' . $path];
    }
    if ($command === '' || strlen($command) > 240) {
        return ['ok' => false, 'message' => '启动命令必填且不超过 240 字符'];
    }
    if ($port > 0 && ($port < 1 || $port > 65535)) {
        return ['ok' => false, 'message' => '端口需在 1-65535 之间'];
    }
    $exists = fetch_one('SELECT id FROM ' . t('project_apps') . ' WHERE name = ?', [$name]);
    if ($exists && (int)$exists['id'] !== (int)($input['id'] ?? 0)) {
        return ['ok' => false, 'message' => '项目名已存在'];
    }
    return ['ok' => true];
}
