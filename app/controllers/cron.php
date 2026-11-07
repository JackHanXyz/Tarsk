<?php
declare(strict_types=1);

/**
 * 计划任务（Windows 走 schtasks，Linux 走 crontab）
 */
function cron_page(): void
{
    $tasks = fetch_all('SELECT * FROM ' . t('cron') . ' ORDER BY id DESC');
    foreach ($tasks as &$task) {
        $task['system_state'] = cron_system_state((int)$task['id']);
        $task['schedule_text'] = cron_schedule_text($task['schedule'], $task['run_time']);
    }
    unset($task);

    render('cron', [
        'title' => '计划任务',
        'active' => 'cron',
        'tasks' => $tasks,
        'scheduler' => PHP_OS_FAMILY === 'Windows' ? 'Windows 任务计划程序' : 'crontab',
        'command_exists' => PHP_OS_FAMILY === 'Windows' ? command_exists('schtasks') !== null : command_exists('crontab') !== null,
    ], 'app');
}

function cron_create(): void
{
    $name = trim((string)($_POST['name'] ?? ''));
    $schedule = (string)($_POST['schedule'] ?? 'daily');
    $runTime = trim((string)($_POST['run_time'] ?? '03:00'));
    $command = trim((string)($_POST['command'] ?? ''));

    $allowed = ['every_minute', 'every_5_minutes', 'hourly', 'daily', 'weekly', 'boot'];
    if (!in_array($schedule, $allowed, true)) {
        $schedule = 'daily';
    }
    if ($name === '' || $command === '') {
        flash('error', '任务名称与执行命令不能为空');
        redirect('/cron');
    }
    if (!preg_match('/^[0-9]{1,2}:[0-9]{2}$/', $runTime)) {
        $runTime = '03:00';
    }

    execute(
        'INSERT INTO ' . t('cron') . ' (name, schedule, run_time, command, status, created_at) VALUES (?,?,?,?,?,?)',
        [$name, $schedule, $runTime, $command, 'enabled', now()]
    );
    $task = fetch_one('SELECT * FROM ' . t('cron') . ' ORDER BY id DESC LIMIT 1');
    $result = cron_register($task);
    execute(
        'UPDATE ' . t('cron') . ' SET last_result = ? WHERE id = ?',
        [mb_substr($result['message'], 0, 255), $task['id']]
    );
    log_action('创建计划任务', $name . ' · ' . cron_schedule_text($schedule, $runTime));
    flash($result['ok'] ? 'success' : 'warning', $result['message']);
    redirect('/cron');
}

function cron_toggle(int $id): void
{
    $task = fetch_one('SELECT * FROM ' . t('cron') . ' WHERE id = ?', [$id]);
    if (!$task) {
        flash('error', '任务不存在');
        redirect('/cron');
    }
    $next = $task['status'] === 'enabled' ? 'disabled' : 'enabled';
    execute('UPDATE ' . t('cron') . ' SET status = ? WHERE id = ?', [$next, $id]);
    $task['status'] = $next;

    if (PHP_OS_FAMILY === 'Windows') {
        $flag = $next === 'enabled' ? '/ENABLE' : '/DISABLE';
        $res = run_cmd('schtasks /Change /TN ' . escapeshellarg(cron_task_name((int)$task['id'])) . ' ' . $flag, 20);
        $message = $res['code'] === 0 ? '系统任务已' . ($next === 'enabled' ? '启用' : '停用') : ('系统任务修改失败：' . ($res['error'] ?: '请以管理员身份运行'));
    } else {
        cron_sync_linux();
        $message = 'crontab 已同步';
    }

    log_action('切换计划任务', $task['name'] . ' -> ' . $next, 'warning');
    flash($next === 'enabled' ? 'success' : 'warning', $message);
    redirect('/cron');
}

function cron_run(int $id): void
{
    $task = fetch_one('SELECT * FROM ' . t('cron') . ' WHERE id = ?', [$id]);
    if (!$task) {
        flash('error', '任务不存在');
        redirect('/cron');
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $res = run_cmd('schtasks /Run /TN ' . escapeshellarg(cron_task_name($id)), 20);
        $ok = $res['code'] === 0;
        $message = $ok ? '已触发系统任务' : ('触发失败：' . ($res['error'] ?: '请以管理员身份运行'));
    } else {
        $res = run_cmd('bash -lc ' . escapeshellarg((string)$task['command']), 60);
        $ok = $res['ok'];
        $message = $ok ? '命令已执行' : ('执行失败：' . $res['error']);
    }

    execute(
        'UPDATE ' . t('cron') . ' SET last_run_at = ?, last_result = ? WHERE id = ?',
        [now(), mb_substr($message, 0, 255), $id]
    );
    log_action('手动执行计划任务', (string)$task['name']);
    flash($ok ? 'success' : 'error', $message);
    redirect('/cron');
}

function cron_delete(int $id): void
{
    $task = fetch_one('SELECT * FROM ' . t('cron') . ' WHERE id = ?', [$id]);
    if (!$task) {
        flash('error', '任务不存在');
        redirect('/cron');
    }

    if (PHP_OS_FAMILY === 'Windows') {
        run_cmd('schtasks /Delete /TN ' . escapeshellarg(cron_task_name($id)) . ' /F', 20);
    }
    execute('DELETE FROM ' . t('cron') . ' WHERE id = ?', [$id]);
    if (PHP_OS_FAMILY !== 'Windows') {
        cron_sync_linux();
    }
    log_action('删除计划任务', (string)$task['name'], 'danger');
    flash('success', '任务已删除');
    redirect('/cron');
}

/* ------------------------------- 系统集成 ------------------------------- */

function cron_task_name(int $id): string
{
    return 'Tarsk_Task_' . $id;
}

function cron_schedule_text(string $schedule, string $runTime = ''): string
{
    return [
        'every_minute' => '每分钟',
        'every_5_minutes' => '每 5 分钟',
        'hourly' => '每小时',
        'daily' => '每天 ' . $runTime,
        'weekly' => '每周日 ' . $runTime,
        'boot' => '开机时',
    ][$schedule] ?? $schedule;
}

function cron_register(array $task): array
{
    $id = (int)$task['id'];
    $command = trim((string)$task['command']);
    if (preg_match('/^cmd(\.exe)?\s+\/c\s+/i', $command)) {
        $command = preg_replace('/^cmd(\.exe)?\s+\/c\s+/i', '', $command);
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $arguments = cron_windows_schedule_args((string)$task['schedule'], (string)$task['run_time']);
        $trigger = 'cmd /c ' . $command;
        $cmd = 'schtasks /Create /TN ' . escapeshellarg(cron_task_name($id))
            . ' /TR ' . escapeshellarg($trigger)
            . ' ' . $arguments . ' /F';
        $res = run_cmd($cmd, 30);
        if ($res['code'] === 0) {
            return ['ok' => true, 'message' => '已注册到 Windows 任务计划程序'];
        }
        return ['ok' => false, 'message' => '注册失败：' . ($res['error'] ?: '请以管理员身份运行面板')];
    }

    cron_sync_linux();
    return ['ok' => true, 'message' => '已同步到 crontab'];
}

function cron_windows_schedule_args(string $schedule, string $runTime): string
{
    switch ($schedule) {
        case 'every_minute':
            return '/SC MINUTE /MO 1';
        case 'every_5_minutes':
            return '/SC MINUTE /MO 5';
        case 'hourly':
            return '/SC HOURLY /MO 1';
        case 'weekly':
            return '/SC WEEKLY /D SUN /ST ' . $runTime;
        case 'boot':
            return '/SC ONSTART';
        case 'daily':
        default:
            return '/SC DAILY /ST ' . $runTime;
    }
}

function cron_system_state(int $id): string
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return 'crontab';
    }
    $res = run_cmd('schtasks /Query /TN ' . escapeshellarg(cron_task_name($id)) . ' /FO LIST', 15);
    if ($res['code'] !== 0) {
        return '未注册';
    }
    if (stripos($res['output'], 'Disabled') !== false || stripos($res['output'], '已禁用') !== false) {
        return '已禁用';
    }
    return '已注册';
}

function cron_sync_linux(): void
{
    $tasks = fetch_all('SELECT * FROM ' . t('cron') . ' WHERE status = ?', ['enabled']);
    $lines = ['# Tarsk managed crontab - do not edit this block manually'];
    foreach ($tasks as $task) {
        $expr = cron_linux_expression((string)$task['schedule'], (string)$task['run_time']);
        $lines[] = $expr . ' ' . str_replace("\n", ' ', (string)$task['command']) . ' # tarsk:' . $task['id'];
    }
    $file = storage_path('tmp/crontab_' . bin2hex(random_bytes(4)) . '.txt');
    file_put_contents($file, implode("\n", $lines) . "\n");
    run_cmd('crontab ' . escapeshellarg($file), 20);
    @unlink($file);
}

function cron_linux_expression(string $schedule, string $runTime): string
{
    [$hour, $minute] = array_pad(explode(':', $runTime), 2, '0');
    return [
        'every_minute' => '* * * * *',
        'every_5_minutes' => '*/5 * * * *',
        'hourly' => '0 * * * *',
        'daily' => (int)$minute . ' ' . (int)$hour . ' * * *',
        'weekly' => (int)$minute . ' ' . (int)$hour . ' * * 0',
        'boot' => '@reboot',
    ][$schedule] ?? '* * * * *';
}
