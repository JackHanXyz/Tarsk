<?php
declare(strict_types=1);

/**
 * 进程管理：查看真实进程并按 PID 结束
 */
function processes_page(): void
{
    $snapshot = process_snapshot();
    $metrics = system_metrics();

    render('processes', [
        'title' => '进程管理',
        'active' => 'processes',
        'items' => $snapshot['items'],
        'total' => $snapshot['total'],
        'error' => $snapshot['error'],
        'metrics' => $metrics,
    ], 'app');
}

function process_snapshot(): array
{
    $result = ['items' => [], 'total' => 0, 'error' => ''];

    if (PHP_OS_FAMILY === 'Windows') {
        $script = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue'
$all = Get-Process
$count = @($all).Count
$items = @($all | Sort-Object -Property WorkingSet64 -Descending | Select-Object -First 45 | ForEach-Object {
    @{
        id = $_.Id
        name = $_.ProcessName
        mem = [int64]$_.WorkingSet64
        cpu = [double]$_.CPU
        started = if ($_.StartTime) { $_.StartTime.ToString('yyyy-MM-dd HH:mm') } else { '' }
    }
})
@{ total = $count; items = $items } | ConvertTo-Json -Compress -Depth 4
PS;
        $data = decode_json_loose(ps_run($script, 30)['output']);
        if (is_array($data)) {
            $result['total'] = (int)($data['total'] ?? 0);
            $items = $data['items'] ?? [];
            if (isset($items['id'])) {
                $items = [$items];
            }
            foreach ($items as $item) {
                $result['items'][] = [
                    'id' => (int)($item['id'] ?? 0),
                    'name' => (string)($item['name'] ?? ''),
                    'mem' => (int)($item['mem'] ?? 0),
                    'cpu' => (float)($item['cpu'] ?? 0),
                    'started' => (string)($item['started'] ?? ''),
                ];
            }
        } else {
            $result['error'] = '无法读取进程列表，请确认 PowerShell 可用';
        }
    } else {
        $ps = run_cmd('ps -eo pid,comm,%mem,%cpu,etime --sort=-%mem | head -n 46', 20);
        $lines = preg_split('/\r?\n/', trim($ps['output']));
        array_shift($lines);
        foreach ($lines as $line) {
            $cols = preg_split('/\s+/', trim($line), 5);
            if (count($cols) < 5) {
                continue;
            }
            $result['items'][] = [
                'id' => (int)$cols[0],
                'name' => $cols[1],
                'cpu' => (float)$cols[3],
                'mem' => 0,
                'started' => $cols[4],
            ];
        }
        $result['total'] = count($result['items']);
    }

    return $result;
}

function processes_kill(): void
{
    $pid = (int)($_POST['pid'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));

    if ($pid <= 0) {
        flash('error', '进程号不合法');
        redirect('/processes');
    }
    if (in_array($pid, [0, 4, 8], true)) {
        flash('error', '该进程属于系统核心进程，禁止结束');
        redirect('/processes');
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $res = ps_run('$p = Get-Process -Id ' . $pid . ' -ErrorAction SilentlyContinue; if ($p) { Stop-Process -Id ' . $pid . ' -Force; "KILLED" } else { "NOT_FOUND" }', 25);
        $ok = stripos($res['output'], 'KILLED') !== false;
    } else {
        $res = run_cmd('kill -9 ' . $pid . ' 2>&1', 15);
        $ok = $res['code'] === 0;
    }

    log_action('结束进程', $name . ' (PID ' . $pid . ')', $ok ? 'warning' : 'danger');
    flash($ok ? 'success' : 'error', $ok ? ('进程 ' . ($name ?: $pid) . ' 已结束') : ('结束失败，可能权限不足或进程已退出'));
    redirect('/processes');
}
