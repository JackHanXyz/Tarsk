<?php
declare(strict_types=1);

/**
 * 通知中心：邮件（纯 PHP SMTP）、Webhook（飞书/钉钉/通用）、资源阈值告警
 */

function notify_channels(): array
{
    $raw = (string)setting('notify_channels', '');
    $list = array_filter(array_map('trim', explode(',', $raw)));
    return array_values(array_intersect($list, ['mail', 'webhook']));
}

function notify_channel_labels(): array
{
    return ['mail' => '邮件 SMTP', 'webhook' => 'Webhook（飞书/钉钉/自定义）'];
}

/**
 * 统一发送入口：返回每个渠道的执行结果
 *
 * @param array $meta 可选元数据：kind（告警类型）、metric / value / threshold（指标阈值）、record=false 可不落库
 */
function notify(string $title, string $message, string $level = 'info', array $meta = []): array
{
    $results = [];
    $text = '【' . setting('panel_name', 'Tarsk') . '】' . $title . "\n" . $message . "\n时间：" . now();

    foreach (notify_channels() as $channel) {
        if ($channel === 'mail') {
            $results['mail'] = notify_mail($title, $text);
        } elseif ($channel === 'webhook') {
            $results['webhook'] = notify_webhook($text);
        }
    }

    if (($meta['record'] ?? true) !== false) {
        alerts_record(
            (string)($meta['kind'] ?? 'notice'),
            in_array($level, ['info', 'warning', 'danger'], true) ? $level : 'info',
            $title,
            $message,
            $meta,
            $results
        );
    }
    return $results;
}

/* ---------------------------- 告警历史 ---------------------------- */

/**
 * 告警落库：无论有没有配置通知渠道都记一条，便于回溯
 */
function alerts_record(string $kind, string $level, string $title, string $message, array $meta = [], array $channels = []): void
{
    try {
        $channelText = [];
        foreach ($channels as $name => $result) {
            $channelText[] = $name . ':' . (!empty($result['ok']) ? 'ok' : 'fail');
        }
        execute(
            'INSERT INTO ' . t('alerts') . ' (kind, level, title, message, metric, value, threshold, sent, channel_result, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                mb_substr($kind, 0, 40),
                $level,
                mb_substr($title, 0, 190),
                mb_substr($message, 0, 4000),
                mb_substr((string)($meta['metric'] ?? ''), 0, 40),
                (float)($meta['value'] ?? 0),
                (float)($meta['threshold'] ?? 0),
                $channels ? 1 : 0,
                mb_substr(implode(', ', $channelText), 0, 255),
                now(),
            ]
        );
        // 只保留最近 180 天 / 最多 5000 条
        if (random_int(1, 20) === 1) {
            execute('DELETE FROM ' . t('alerts') . ' WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 180 * 86400)]);
        }
    } catch (Throwable $e) {
        // 落库失败不影响通知本身
    }
}

function alerts_kind_labels(): array
{
    return [
        'resource' => '资源阈值',
        'site' => '站点巡检',
        'task' => '计划任务',
        'notice' => '一般通知',
    ];
}

/**
 * 按天聚合最近 N 天的告警数量
 */
function alerts_daily_series(int $days = 14): array
{
    $from = date('Y-m-d 00:00:00', time() - ($days - 1) * 86400);
    $rows = fetch_all(
        'SELECT DATE(created_at) AS d, COUNT(*) AS n,
                SUM(level = "danger") AS danger, SUM(level = "warning") AS warning
         FROM ' . t('alerts') . ' WHERE created_at >= ? GROUP BY DATE(created_at)',
        [$from]
    );
    $map = [];
    foreach ($rows as $row) {
        $map[(string)$row['d']] = $row;
    }
    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $day = date('Y-m-d', time() - $i * 86400);
        $row = $map[$day] ?? null;
        $series[] = [
            'date' => $day,
            'label' => date('m-d', strtotime($day)),
            'count' => (int)($row['n'] ?? 0),
            'danger' => (int)($row['danger'] ?? 0),
            'warning' => (int)($row['warning'] ?? 0),
        ];
    }
    return $series;
}

/**
 * 纯 PHP SMTP 发信（支持 ssl / starttls / 明文）
 */
function notify_mail(string $subject, string $body): array
{
    $host = trim((string)setting('smtp_host', ''));
    $port = (int)setting('smtp_port', '465');
    $user = trim((string)setting('smtp_user', ''));
    $pass = (string)setting('smtp_pass', '');
    $secure = (string)setting('smtp_secure', 'ssl');
    $from = trim((string)setting('smtp_from', ''));
    $to = trim((string)setting('smtp_to', ''));

    if ($host === '' || $to === '') {
        return ['ok' => false, 'message' => '未配置 SMTP 服务器或收件人'];
    }
    if ($from === '') {
        $from = $user !== '' ? $user : 'tarsk@' . $host;
    }

    $recipients = array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $to))));
    if (!$recipients) {
        return ['ok' => false, 'message' => '收件人为空'];
    }

    $prefix = $secure === 'ssl' ? 'ssl://' : 'tcp://';
    $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $socket = @stream_socket_client($prefix . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        return ['ok' => false, 'message' => '连接 SMTP 失败：' . ($errstr ?: $errno)];
    }
    stream_set_timeout($socket, 15);

    $read = function () use ($socket): string {
        $data = '';
        while (($line = fgets($socket, 515)) !== false) {
            $data .= $line;
            if (preg_match('/^\d{3}\s/', $line)) {
                break;
            }
        }
        return $data;
    };
    $expect = function (int $code) use ($socket, $read): bool {
        $response = $read();
        return (bool)preg_match('/^' . $code . '/', $response);
    };
    $cmd = function (string $line, int $code) use ($socket, $read, $expect): bool {
        fwrite($socket, $line . "\r\n");
        return $expect($code);
    };

    $ok = $expect(220);
    if (!$ok) {
        fclose($socket);
        return ['ok' => false, 'message' => 'SMTP 未返回 220 就绪'];
    }
    $hostname = gethostname() ?: 'localhost';
    $cmd('EHLO ' . $hostname, 250);
    if ($secure === 'tls') {
        $cmd('STARTTLS', 220);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return ['ok' => false, 'message' => 'STARTTLS 握手失败'];
        }
        $cmd('EHLO ' . $hostname, 250);
    }

    if ($user !== '') {
        if (!$cmd('AUTH LOGIN', 334)) {
            fclose($socket);
            return ['ok' => false, 'message' => '服务器不接受 AUTH LOGIN'];
        }
        if (!$cmd(base64_encode($user), 334)) {
            fclose($socket);
            return ['ok' => false, 'message' => '用户名被拒绝'];
        }
        if (!$cmd(base64_encode($pass), 235)) {
            fclose($socket);
            return ['ok' => false, 'message' => 'SMTP 认证失败，请检查账号密码'];
        }
    }

    if (!$cmd('MAIL FROM:<' . $from . '>', 250)) {
        fclose($socket);
        return ['ok' => false, 'message' => 'MAIL FROM 被拒绝'];
    }
    foreach ($recipients as $rcpt) {
        if (!$cmd('RCPT TO:<' . $rcpt . '>', 250)) {
            fclose($socket);
            return ['ok' => false, 'message' => '收件人被拒绝：' . $rcpt];
        }
    }
    if (!$cmd('DATA', 354)) {
        fclose($socket);
        return ['ok' => false, 'message' => 'DATA 被拒绝'];
    }

    $headers = [
        'From: ' . $from,
        'To: ' . implode(', ', $recipients),
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'Date: ' . date('r'),
    ];
    $payload = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
    fwrite($socket, $payload . "\r\n.\r\n");
    $sent = $expect(250);
    $cmd('QUIT', 221);
    fclose($socket);

    return $sent
        ? ['ok' => true, 'message' => '邮件已发送至 ' . implode(', ', $recipients)]
        : ['ok' => false, 'message' => '邮件内容被服务器拒绝'];
}

/**
 * Webhook 推送：同时兼容飞书（msg_type）与钉钉（msgtype）格式
 */
function notify_webhook(string $text): array
{
    $url = trim((string)setting('notify_webhook', ''));
    if ($url === '' || !preg_match('#^https?://#', $url)) {
        return ['ok' => false, 'message' => '未配置合法的 Webhook 地址'];
    }

    $payload = json_encode([
        'msg_type' => 'text',
        'content' => ['text' => $text],
        'msgtype' => 'text',
        'text' => ['content' => $text],
    ], JSON_UNESCAPED_UNICODE);

    $result = ['ok' => false, 'message' => '未发送'];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $result = $code >= 200 && $code < 300
            ? ['ok' => true, 'message' => 'Webhook 已推送（HTTP ' . $code . '）']
            : ['ok' => false, 'message' => 'Webhook 失败：HTTP ' . $code . ' ' . $error . ' ' . mb_substr((string)$body, 0, 120)];
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $result = $body !== false
            ? ['ok' => true, 'message' => 'Webhook 已推送']
            : ['ok' => false, 'message' => 'Webhook 请求失败'];
    }
    return $result;
}

/**
 * 资源阈值告警：命中即落库，配置了通知渠道才推送（带冷却时间避免刷屏）
 */
function alert_check_resources(array $metrics): ?array
{
    $cooldown = max(5, (int)setting('alert_cooldown', '30'));
    $lastAt = (string)setting('alert_last_at', '');
    if ($lastAt !== '' && time() - strtotime($lastAt) < $cooldown * 60) {
        return null;
    }

    $hits = [];
    $cpu = (float)($metrics['cpu'] ?? 0);
    $cpuLimit = (float)setting('alert_cpu', '90');
    if ($cpu >= $cpuLimit) {
        $hits[] = ['metric' => 'cpu', 'label' => 'CPU 使用率', 'value' => $cpu, 'threshold' => $cpuLimit,
            'text' => 'CPU 使用率 ' . round($cpu, 1) . '%'];
    }
    $memTotal = (float)($metrics['mem_total'] ?? 0);
    $memUsed = (float)($metrics['mem_used'] ?? 0);
    if ($memTotal > 0) {
        $memPct = $memUsed / $memTotal * 100;
        $memLimit = (float)setting('alert_mem', '90');
        if ($memPct >= $memLimit) {
            $hits[] = ['metric' => 'mem', 'label' => '内存使用率', 'value' => $memPct, 'threshold' => $memLimit,
                'text' => '内存使用率 ' . round($memPct, 1) . '%（' . bytes($memUsed) . ' / ' . bytes($memTotal) . '）'];
        }
    }
    $diskTotal = (float)($metrics['disk_total'] ?? 0);
    $diskUsed = (float)($metrics['disk_used'] ?? 0);
    if ($diskTotal > 0) {
        $diskPct = $diskUsed / $diskTotal * 100;
        $diskLimit = (float)setting('alert_disk', '90');
        if ($diskPct >= $diskLimit) {
            $hits[] = ['metric' => 'disk', 'label' => '磁盘使用率', 'value' => $diskPct, 'threshold' => $diskLimit,
                'text' => '磁盘使用率 ' . round($diskPct, 1) . '%（' . bytes($diskUsed) . ' / ' . bytes($diskTotal) . '）'];
        }
    }

    if (!$hits) {
        return null;
    }

    $texts = array_map(static fn($h) => $h['text'], $hits);
    $channels = notify_channels();
    $result = $channels ? notify('资源告警', implode("\n", $texts), 'warning', ['record' => false]) : [];

    // 每个指标单独落库，便于按指标看趋势
    foreach ($hits as $hit) {
        alerts_record('resource', 'warning', '资源告警 · ' . $hit['label'], $hit['text'], $hit, $result);
    }
    setting_set('alert_last_at', now());
    log_action('资源告警', implode('；', $texts), 'warning');
    return $result;
}
