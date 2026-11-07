<?php
declare(strict_types=1);

function metrics_api(): void
{
    $metrics = system_metrics();
    json_response([
        'ok' => true,
        'metrics' => [
            'cpu' => (float)($metrics['cpu'] ?? 0),
            'load1' => (float)($metrics['load1'] ?? 0),
            'mem_used' => (int)($metrics['mem_used'] ?? 0),
            'mem_total' => (int)($metrics['mem_total'] ?? 0),
            'disk_used' => (int)($metrics['disk_used'] ?? 0),
            'disk_total' => (int)($metrics['disk_total'] ?? 0),
            'net_rx' => (int)($metrics['net_rx'] ?? 0),
            'net_tx' => (int)($metrics['net_tx'] ?? 0),
            'uptime' => (int)($metrics['uptime'] ?? 0),
            'time' => $metrics['time'] ?? date('H:i:s'),
            'mem_percent' => percent($metrics['mem_used'] ?? 0, $metrics['mem_total'] ?? 0),
            'disk_percent' => percent($metrics['disk_used'] ?? 0, $metrics['disk_total'] ?? 0),
        ],
        'services' => services_status(),
    ]);
}

function monitor_page(): void
{
    $metrics = system_metrics();
    record_metrics_sample($metrics);

    $rows = fetch_all(
        'SELECT * FROM ' . t('monitor') . ' WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY id ASC'
    );

    $series = [];
    foreach ($rows as $row) {
        $series[] = [
            't' => date('H:i', strtotime($row['created_at'])),
            'cpu' => (float)$row['cpu'],
            'mem' => round(percent($row['mem_used'], $row['mem_total']), 1),
            'disk' => round(percent($row['disk_used'], $row['disk_total']), 1),
            'rx' => (int)$row['net_rx'],
            'tx' => (int)$row['net_tx'],
        ];
    }

    render('monitor', [
        'title' => '监控',
        'active' => 'monitor',
        'metrics' => $metrics,
        'series' => $series,
        'services' => services_status(),
        'drives' => $metrics['drives'] ?? [],
    ], 'app');
}
