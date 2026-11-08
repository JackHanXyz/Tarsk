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
 */
function notify(string $title, string $message, string $level = 'info'): array
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
    return $results;
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
 * 资源阈值告警（带冷却时间，避免刷屏）
 */
function alert_check_resources(array $metrics): ?array
{
    if (!notify_channels()) {
        return null;
    }
    $cooldown = max(5, (int)setting('alert_cooldown', '30'));
    $lastAt = (string)setting('alert_last_at', '');
    if ($lastAt !== '' && time() - strtotime($lastAt) < $cooldown * 60) {
        return null;
    }

    $hits = [];
    $cpu = (float)($metrics['cpu'] ?? 0);
    if ($cpu >= (float)setting('alert_cpu', '90')) {
        $hits[] = 'CPU 使用率 ' . round($cpu, 1) . '%';
    }
    $memTotal = (float)($metrics['mem_total'] ?? 0);
    $memUsed = (float)($metrics['mem_used'] ?? 0);
    if ($memTotal > 0) {
        $memPct = $memUsed / $memTotal * 100;
        if ($memPct >= (float)setting('alert_mem', '90')) {
            $hits[] = '内存使用率 ' . round($memPct, 1) . '%（' . bytes($memUsed) . ' / ' . bytes($memTotal) . '）';
        }
    }
    $diskTotal = (float)($metrics['disk_total'] ?? 0);
    $diskUsed = (float)($metrics['disk_used'] ?? 0);
    if ($diskTotal > 0) {
        $diskPct = $diskUsed / $diskTotal * 100;
        if ($diskPct >= (float)setting('alert_disk', '90')) {
            $hits[] = '磁盘使用率 ' . round($diskPct, 1) . '%（' . bytes($diskUsed) . ' / ' . bytes($diskTotal) . '）';
        }
    }

    if (!$hits) {
        return null;
    }
    $result = notify('资源告警', implode("\n", $hits), 'warning');
    setting_set('alert_last_at', now());
    log_action('资源告警', implode('；', $hits), 'warning');
    return $result;
}
