<?php
declare(strict_types=1);

/**
 * Web 终端：在面板内执行真实系统命令
 */
function terminal_page(): void
{
    $history = $_SESSION['term_history'] ?? [];

    render('terminal', [
        'title' => 'Web 终端',
        'active' => 'terminal',
        'history' => $history,
        'cwd' => app_root(),
        'platform' => PHP_OS_FAMILY,
        'user' => get_current_user() ?: (PHP_OS_FAMILY === 'Windows' ? (getenv('USERNAME') ?: 'user') : 'user'),
    ], 'app');
}

function terminal_run(): void
{
    $command = trim((string)($_POST['command'] ?? ''));
    if ($command === '') {
        json_response(['ok' => false, 'message' => '请输入命令']);
    }
    if (mb_strlen($command) > 2000) {
        json_response(['ok' => false, 'message' => '命令过长（最多 2000 字符）']);
    }
    if (!function_exists('proc_open')) {
        json_response(['ok' => false, 'message' => 'proc_open 被禁用，无法执行命令']);
    }

    $started = microtime(true);
    if (PHP_OS_FAMILY === 'Windows') {
        $result = run_cmd('cmd /c ' . $command . ' 2>&1', 45);
    } else {
        $result = run_cmd('bash -lc ' . escapeshellarg($command) . ' 2>&1', 45);
    }

    $output = trim((string)$result['output']);
    if ($result['error'] !== '' && trim($result['error']) !== $output) {
        $output .= ($output === '' ? '' : "\n") . trim($result['error']);
    }
    if ($output === '') {
        $output = '(无输出)';
    }
    $output = mb_substr($output, 0, 20000);

    $entry = [
        'command' => $command,
        'output' => $output,
        'code' => (int)$result['code'],
        'time' => date('H:i:s'),
        'duration' => round(microtime(true) - $started, 2),
    ];

    $history = $_SESSION['term_history'] ?? [];
    $history[] = $entry;
    $_SESSION['term_history'] = array_slice($history, -20);

    log_action('终端执行', mb_substr($command, 0, 120));
    json_response(['ok' => true, 'entry' => $entry]);
}
