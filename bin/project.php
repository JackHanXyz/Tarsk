<?php
declare(strict_types=1);

/**
 * 应用自启动 CLI：php bin/project.php --id=N [--stop]
 * 开机自启（schtasks ONSTART / crontab @reboot）调用本脚本拉起项目。
 */

if (PHP_SAPI !== 'cli') {
    exit("cli only\n");
}

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    exit("config.php missing\n");
}
$GLOBALS['config'] = require $configFile;
require dirname(__DIR__) . '/app/bootstrap.php';

$id = 0;
$stop = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--stop') {
        $stop = true;
    } elseif (preg_match('/^--id=(\d+)$/', $arg, $m)) {
        $id = (int)$m[1];
    }
}
if ($id <= 0) {
    exit("usage: php bin/project.php --id=N [--stop]\n");
}

$row = fetch_one('SELECT * FROM ' . t('project_apps') . ' WHERE id = ?', [$id]);
if (!$row) {
    fwrite(STDERR, "project #{$id} not found\n");
    exit(1);
}

$result = $stop ? project_stop($row) : project_start($row);
echo '[' . now() . '] #' . $id . ' ' . $row['name'] . ' => ' . $result['message'] . "\n";
exit($result['ok'] ? 0 : 1);
