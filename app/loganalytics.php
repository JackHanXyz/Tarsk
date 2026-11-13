<?php
declare(strict_types=1);

/**
 * 访问日志分析：解析 Nginx combined 格式，出 PV/UV、状态码、Top URL、
 * 来源、蜘蛛与按小时趋势。只读日志文件，不做任何写入。
 */

function log_spider_map(): array
{
    return [
        'Baiduspider' => '百度',
        'Googlebot' => 'Google',
        'bingbot' => 'Bing',
        'Sogou web spider' => '搜狗',
        'Sogou' => '搜狗',
        '360Spider' => '360',
        'HaoSouSpider' => '360',
        'YisouSpider' => '神马',
        'YandexBot' => 'Yandex',
        'Bytespider' => '字节',
        'PetalBot' => '华为',
        'Applebot' => 'Apple',
        'DuckDuckBot' => 'DuckDuckGo',
        'SemrushBot' => 'Semrush',
        'AhrefsBot' => 'Ahrefs',
        'MJ12bot' => 'Majestic',
        'GPTBot' => 'OpenAI',
        'ClaudeBot' => 'Claude',
        'CCBot' => 'Common Crawl',
        'python-requests' => '脚本',
        'curl/' => '脚本',
        'Go-http-client' => '脚本',
    ];
}

/**
 * 可分析的日志文件列表：托管 Nginx 的站点日志 + 实例级日志
 */
function log_sources(): array
{
    $sources = [];
    foreach (fetch_all('SELECT id, name, domain FROM ' . t('sites') . ' ORDER BY name') as $site) {
        $path = site_log_path($site);
        if ($path !== '' && is_file($path)) {
            $sources[] = [
                'key' => (string)$site['name'],
                'label' => $site['name'] . ($site['domain'] !== '' ? ' · ' . $site['domain'] : ''),
                'path' => $path,
                'size' => (int)@filesize($path),
                'mtime' => (int)@filemtime($path),
            ];
        }
    }

    $root = function_exists('managed_nginx_root') ? managed_nginx_root() : null;
    if ($root !== null) {
        foreach (['logs/access.log', 'logs/error.log'] as $rel) {
            $path = $root . '/' . $rel;
            if (is_file($path) && basename($rel) === 'access.log') {
                $sources[] = [
                    'key' => 'nginx-access',
                    'label' => 'Nginx 全局访问日志',
                    'path' => $path,
                    'size' => (int)@filesize($path),
                    'mtime' => (int)@filemtime($path),
                ];
            }
        }
    }
    return $sources;
}

function log_source_by_key(string $key): ?array
{
    foreach (log_sources() as $source) {
        if ($source['key'] === $key) {
            return $source;
        }
    }
    return null;
}

/**
 * 从文件尾部读取最多 $maxBytes 字节，返回行数组（丢弃可能被截断的首行）
 */
function log_tail_lines(string $path, int $maxBytes): array
{
    $size = (int)@filesize($path);
    if ($size <= 0) {
        return [];
    }
    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return [];
    }
    $read = min($size, $maxBytes);
    fseek($handle, -$read, SEEK_END);
    $blob = (string)fread($handle, $read);
    fclose($handle);

    $lines = preg_split('/\r?\n/', $blob) ?: [];
    if ($read < $size && $lines) {
        array_shift($lines);
    }
    return array_values(array_filter($lines, static fn($l) => trim((string)$l) !== ''));
}

function log_parse_time(string $value): ?int
{
    // 08/Nov/2026:14:30:52 +0800
    $dt = \DateTime::createFromFormat('d/M/Y:H:i:s O', $value);
    if ($dt === false) {
        $ts = strtotime($value);
        return $ts !== false ? $ts : null;
    }
    return $dt->getTimestamp();
}

function log_spider_of(string $ua): string
{
    if ($ua === '' || $ua === '-') {
        return '';
    }
    foreach (log_spider_map() as $needle => $label) {
        if (stripos($ua, $needle) !== false) {
            return $label;
        }
    }
    return '';
}

function log_status_class(int $status): string
{
    if ($status >= 500) {
        return '5xx';
    }
    if ($status >= 400) {
        return '4xx';
    }
    if ($status >= 300) {
        return '3xx';
    }
    if ($status >= 200) {
        return '2xx';
    }
    return '1xx';
}

/**
 * 解析并聚合访问日志
 *
 * @param string $range all|1h|24h|7d
 */
function log_analyze(string $path, string $range = 'all', int $maxBytes = 8388608): array
{
    $ranges = ['all' => 0, '1h' => 3600, '24h' => 86400, '7d' => 604800];
    $window = $ranges[$range] ?? 0;
    $since = $window > 0 ? time() - $window : 0;

    $lines = log_tail_lines($path, $maxBytes);
    $stats = [
        'lines' => count($lines),
        'parsed' => 0,
        'requests' => 0,
        'bytes' => 0,
        'ips' => [],
        'status' => ['1xx' => 0, '2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0],
        'codes' => [],
        'paths' => [],
        'referers' => [],
        'spiders' => [],
        'methods' => [],
        'hours' => [],
        'errors' => [],
        'ua_bots' => 0,
        'unparsed' => 0,
        'first_time' => null,
        'last_time' => null,
    ];

    $pattern = '/^(\S+) \S+ \S+ \[([^\]]+)\] "([^"]*)" (\d{3}) (\S+) "([^"]*)" "([^"]*)"/';

    foreach ($lines as $line) {
        if (!preg_match($pattern, $line, $m)) {
            $stats['unparsed']++;
            continue;
        }
        $time = log_parse_time($m[2]);
        if ($time === null) {
            $stats['unparsed']++;
            continue;
        }
        if ($since > 0 && $time < $since) {
            continue;
        }
        $stats['parsed']++;
        $stats['requests']++;

        $ip = $m[1];
        $request = $m[3];
        $status = (int)$m[4];
        $bytes = ctype_digit($m[5]) ? (int)$m[5] : 0;
        $referer = $m[6];
        $ua = $m[7];

        $stats['bytes'] += $bytes;
        if (!isset($stats['ips'][$ip])) {
            $stats['ips'][$ip] = 0;
        }
        $stats['ips'][$ip]++;

        $class = log_status_class($status);
        $stats['status'][$class]++;
        $stats['codes'][$status] = ($stats['codes'][$status] ?? 0) + 1;

        $method = 'OTHER';
        $target = $request;
        $parts = explode(' ', $request);
        if (count($parts) >= 2) {
            $method = strtoupper($parts[0]);
            $target = $parts[1];
        }
        $stats['methods'][$method] = ($stats['methods'][$method] ?? 0) + 1;

        $pathOnly = parse_url($target, PHP_URL_PATH) ?: $target;
        $pathOnly = mb_substr($pathOnly, 0, 120);
        $stats['paths'][$pathOnly] = ($stats['paths'][$pathOnly] ?? 0) + 1;

        if ($status >= 400) {
            $key = $status . ' ' . $pathOnly;
            $stats['errors'][$key] = ($stats['errors'][$key] ?? 0) + 1;
        }

        if ($referer !== '' && $referer !== '-') {
            $host = parse_url($referer, PHP_URL_HOST) ?: '';
            if ($host !== '') {
                $stats['referers'][$host] = ($stats['referers'][$host] ?? 0) + 1;
            }
        }

        $spider = log_spider_of($ua);
        if ($spider !== '') {
            $stats['spiders'][$spider] = ($stats['spiders'][$spider] ?? 0) + 1;
            $stats['ua_bots']++;
        }

        $hourKey = date('m-d H:00', $time);
        $stats['hours'][$hourKey] = ($stats['hours'][$hourKey] ?? 0) + 1;

        if ($stats['first_time'] === null || $time < $stats['first_time']) {
            $stats['first_time'] = $time;
        }
        if ($stats['last_time'] === null || $time > $stats['last_time']) {
            $stats['last_time'] = $time;
        }
    }

    $sortDesc = static function (array $map, int $limit = 10): array {
        arsort($map);
        $out = [];
        foreach (array_slice($map, 0, $limit, true) as $key => $count) {
            $out[] = ['name' => (string)$key, 'count' => (int)$count];
        }
        return $out;
    };

    $stats['uv'] = count($stats['ips']);
    $stats['top_paths'] = $sortDesc($stats['paths'], 15);
    $stats['top_ips'] = $sortDesc($stats['ips'], 10);
    $stats['top_referers'] = $sortDesc($stats['referers'], 10);
    $stats['top_spiders'] = $sortDesc($stats['spiders'], 12);
    $stats['top_errors'] = $sortDesc($stats['errors'], 10);
    $stats['top_codes'] = $sortDesc($stats['codes'], 8);
    $stats['method_list'] = $sortDesc($stats['methods'], 6);

    ksort($stats['hours']);
    $hours = [];
    foreach ($stats['hours'] as $label => $count) {
        $hours[] = ['label' => (string)$label, 'count' => (int)$count];
    }
    $stats['hour_list'] = array_slice($hours, -24);
    $stats['max_hour'] = 0;
    foreach ($stats['hour_list'] as $item) {
        $stats['max_hour'] = max($stats['max_hour'], $item['count']);
    }

    $stats['bot_rate'] = $stats['requests'] > 0 ? round($stats['ua_bots'] / $stats['requests'] * 100, 1) : 0.0;
    $stats['error_rate'] = $stats['requests'] > 0
        ? round(($stats['status']['4xx'] + $stats['status']['5xx']) / $stats['requests'] * 100, 2)
        : 0.0;
    $stats['avg_size'] = $stats['requests'] > 0 ? (int)round($stats['bytes'] / $stats['requests']) : 0;

    return $stats;
}
