<?php
declare(strict_types=1);

function metrics_api(): void
{
    $fresh = (string)($_GET['fresh'] ?? '') === '1';
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
    $metrics = system_metrics($fresh);
    record_metrics_sample($metrics);
    json_response([
        'ok' => true,
        'metrics' => [
            'cpu' => (float)($metrics['cpu'] ?? 0),
            'cpu_cores' => (int)($metrics['cpu_cores'] ?? 0),
            'load1' => (float)($metrics['load1'] ?? 0),
            'load5' => (float)($metrics['load5'] ?? 0),
            'load15' => (float)($metrics['load15'] ?? 0),
            'mem_used' => (int)($metrics['mem_used'] ?? 0),
            'mem_total' => (int)($metrics['mem_total'] ?? 0),
            'swap_used' => (int)($metrics['swap_used'] ?? 0),
            'swap_total' => (int)($metrics['swap_total'] ?? 0),
            'disk_used' => (int)($metrics['disk_used'] ?? 0),
            'disk_total' => (int)($metrics['disk_total'] ?? 0),
            'net_rx' => (int)($metrics['net_rx'] ?? 0),
            'net_tx' => (int)($metrics['net_tx'] ?? 0),
            'net_interfaces' => $metrics['net_interfaces'] ?? [],
            'disk_io' => $metrics['disk_io'] ?? [],
            'drives' => $metrics['drives'] ?? [],
            'sampled_at' => (float)($metrics['sampled_at'] ?? 0),
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

    $range = (string)($_GET['range'] ?? '24h');
    $data = metrics_series($range);
    $rangeLabels = [
        '1h' => '最近 1 小时',
        '24h' => '24 小时',
        '7d' => '7 天',
        '30d' => '30 天',
    ];

    render('monitor', [
        'title' => '监控',
        'active' => 'monitor',
        'metrics' => $metrics,
        'series' => $data['series'],
        'range' => $data['range'],
        'rangeLabel' => $rangeLabels[$data['range']],
        'rangeLabels' => $rangeLabels,
        'seriesUnit' => $data['unit'],
        'digest' => metrics_digest(),
        'services' => services_status(),
        'drives' => $metrics['drives'] ?? [],
    ], 'app');
}
