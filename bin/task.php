<?php
declare(strict_types=1);

/**
 * 计划任务 CLI 入口：php bin/task.php --id=N
 * 由系统调度器（schtasks / crontab）调用，按任务类型分派执行。
 */

if (PHP_SAPI !== 'cli') {
    exit('仅支持命令行运行');
}

$root = dirname(__DIR__);
if (!is_file($root . '/config.php')) {
    fwrite(STDERR, "缺少 config.php，请先完成面板安装。\n");
    exit(1);
}

$args = $argv ?? [];
$id = 0;
foreach ($args as $arg) {
    if (preg_match('/^--id=(\d+)$/', (string)$arg, $m)) {
        $id = (int)$m[1];
    }
}
if ($id <= 0) {
    fwrite(STDERR, "用法：php bin/task.php --id=<任务ID>\n");
    exit(1);
}

$GLOBALS['config'] = require $root . '/config.php';
require $root . '/app/bootstrap.php';

$task = fetch_one('SELECT * FROM ' . t('cron') . ' WHERE id = ?', [$id]);
if (!$task) {
    fwrite(STDERR, "任务 #{$id} 不存在\n");
    exit(1);
}

// 自定义表达式：Windows 用「每分钟触发 + 到期判断」模拟 5 段式
$expr = trim((string)($task['cron_expr'] ?? ''));
if ((string)($task['schedule'] ?? '') === 'custom' && $expr !== '' && !cron_expr_is_reboot($expr)) {
    if (!cron_expr_matches($expr)) {
        exit(0);
    }
}

$result = task_execute($task, 'schedule');
execute(
    'UPDATE ' . t('cron') . ' SET last_run_at = ?, last_result = ? WHERE id = ?',
    [now(), mb_substr($result['message'], 0, 255), $id]
);

echo ($result['ok'] ? '[OK] ' : '[FAIL] ') . $result['message'] . "\n";
exit($result['ok'] ? 0 : 1);
