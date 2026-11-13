<?php
declare(strict_types=1);

/**
 * 首页概览
 */
function dashboard_page(): void
{
    $metrics = system_metrics();
    $services = services_status();
    $mysql = mysql_server_info();
    $docker = docker_info();
    $siteCount = (int)(fetch_one('SELECT COUNT(*) AS n FROM ' . t('sites') . ' WHERE 1=1' . site_scope_sql())['n'] ?? 0);
    $cronCount = (int)(fetch_one('SELECT COUNT(*) AS n FROM ' . t('cron') . ' WHERE status = ?', ['enabled'])['n'] ?? 0);
    $dbCount = count(mysql_databases());
    $logs = fetch_all('SELECT * FROM ' . t('logs') . ' ORDER BY id DESC LIMIT 8');
    $sites = fetch_all('SELECT * FROM ' . t('sites') . ' WHERE 1=1' . site_scope_sql() . ' ORDER BY id DESC LIMIT 5');

    $diskTotal = (int)($metrics['disk_total'] ?? 0);
    $diskUsed = (int)($metrics['disk_used'] ?? 0);

    record_metrics_sample($metrics);

    render('dashboard', [
        'title' => '概览',
        'active' => 'dashboard',
        'metrics' => $metrics,
        'services' => $services,
        'mysql' => $mysql,
        'docker' => $docker,
        'runtime' => runtime_states(),
        'counts' => ['sites' => $siteCount, 'databases' => $dbCount, 'cron' => $cronCount],
        'logs' => $logs,
        'sites' => $sites,
        'disk_usage' => ['used' => $diskUsed, 'total' => $diskTotal],
        'refresh' => (int)setting('refresh_interval', 3),
    ], 'app');
}
