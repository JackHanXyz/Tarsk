<?php
declare(strict_types=1);

/**
 * 异地备份：S3（SigV4）/ 阿里云 OSS（HMAC-SHA1）/ WebDAV
 *
 * 说明：面板运行环境不保证有 curl 扩展，这里统一用 stream context 发请求。
 */

function remote_kind_labels(): array
{
    return [
        's3' => 'S3 / MinIO / 七牛等兼容 S3 的对象存储',
        'oss' => '阿里云 OSS',
        'webdav' => 'WebDAV（坚果云 / Nextcloud / 群晖等）',
    ];
}

function remote_targets(): array
{
    return fetch_all('SELECT * FROM ' . t('remote_targets') . ' ORDER BY id DESC');
}

function remote_target(int $id): ?array
{
    return fetch_one('SELECT * FROM ' . t('remote_targets') . ' WHERE id = ?', [$id]);
}

function remote_target_save(array $input, int $id = 0): array
{
    $kind = (string)($input['kind'] ?? 's3');
    if (!array_key_exists($kind, remote_kind_labels())) {
        return ['ok' => false, 'message' => '类型不合法'];
    }
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'message' => '请填写名称'];
    }
    $endpoint = trim((string)($input['endpoint'] ?? ''));
    if ($endpoint === '') {
        return ['ok' => false, 'message' => '请填写 Endpoint / 服务地址'];
    }
    $values = [
        $name,
        $kind,
        rtrim($endpoint, '/'),
        trim((string)($input['region'] ?? '')),
        trim((string)($input['bucket'] ?? '')),
        trim((string)($input['remote_dir'] ?? 'tarsk')),
        trim((string)($input['access_key'] ?? '')),
        (string)($input['secret_key'] ?? ''),
        trim((string)($input['extra'] ?? '')),
    ];
    if ($id > 0) {
        $old = remote_target($id);
        if (!$old) {
            return ['ok' => false, 'message' => '目标不存在'];
        }
        // 留空表示沿用旧密钥
        if ($values[7] === '') {
            $values[7] = (string)$old['secret_key'];
        }
        execute(
            'UPDATE ' . t('remote_targets') . ' SET name=?, kind=?, endpoint=?, region=?, bucket=?, remote_dir=?, access_key=?, secret_key=?, extra=? WHERE id=?',
            array_merge($values, [$id])
        );
        log_action('修改异地备份目标', $name . ' · ' . $kind, 'warning');
        return ['ok' => true, 'message' => '备份目标已更新'];
    }
    execute(
        'INSERT INTO ' . t('remote_targets') . ' (name, kind, endpoint, region, bucket, remote_dir, access_key, secret_key, extra, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
        array_merge($values, [now()])
    );
    log_action('新增异地备份目标', $name . ' · ' . $kind, 'warning');
    return ['ok' => true, 'message' => '备份目标已新增'];
}

function remote_target_delete(int $id): array
{
    $target = remote_target($id);
    if (!$target) {
        return ['ok' => false, 'message' => '目标不存在'];
    }
    execute('DELETE FROM ' . t('remote_targets') . ' WHERE id = ?', [$id]);
    log_action('删除异地备份目标', (string)$target['name'], 'danger');
    return ['ok' => true, 'message' => '已删除 ' . $target['name']];
}

/* ------------------------------ 工具 ------------------------------ */

/**
 * 逐段 URL 编码，保留分隔符（S3/OSS 的 canonical URI 要求）
 */
function remote_encode_path(string $path): string
{
    $segments = explode('/', $path);
    return implode('/', array_map(static fn($s) => rawurlencode((string)$s), $segments));
}

function remote_join(string $base, string ...$parts): string
{
    $url = rtrim($base, '/');
    foreach ($parts as $part) {
        $part = trim((string)$part, '/');
        if ($part !== '') {
            $url .= '/' . remote_encode_path($part);
        }
    }
    return $url;
}

/**
 * 通用 HTTP 请求（stream context，无 curl 依赖）
 * @return array{ok:bool,status:int,body:string,error:string}
 */
function remote_http(string $method, string $url, array $headers = [], ?string $bodyFile = null, int $timeout = 30): array
{
    $headerLines = [];
    foreach ($headers as $key => $value) {
        $headerLines[] = $key . ': ' . $value;
    }
    $http = [
        'method' => $method,
        'header' => implode("\r\n", $headerLines),
        'timeout' => $timeout,
        'ignore_errors' => true,
        'follow_location' => 0,
    ];
    if ($bodyFile !== null && is_file($bodyFile)) {
        $http['content'] = (string)file_get_contents($bodyFile);
    }
    $context = stream_context_create(['http' => $http, 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    $error = '';
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
            }
        }
    }
    if ($body === false) {
        $last = error_get_last();
        $error = $last['message'] ?? '请求失败';
        $body = '';
    }
    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $body,
        'error' => $error,
    ];
}

/* ------------------------------ WebDAV ------------------------------ */

function remote_dav_url(array $target, string $file = ''): string
{
    $base = rtrim((string)$target['endpoint'], '/');
    $dir = trim((string)$target['remote_dir'], '/');
    return remote_join($base, $dir, $file);
}

function remote_dav_auth(array $target): array
{
    return ['Authorization' => 'Basic ' . base64_encode((string)$target['access_key'] . ':' . (string)$target['secret_key'])];
}

function remote_dav_test(array $target): array
{
    $url = remote_dav_url($target) . '/';
    $result = remote_http('PROPFIND', $url, remote_dav_auth($target) + ['Depth' => '0']);
    if ($result['status'] === 404) {
        // 目录不存在时尝试创建
        $mk = remote_http('MKCOL', $url, remote_dav_auth($target));
        if ($mk['status'] >= 200 && $mk['status'] < 300) {
            return ['ok' => true, 'message' => '连接成功，已创建远端目录'];
        }
        return ['ok' => false, 'message' => '连接失败：目录不存在且创建失败（HTTP ' . $mk['status'] . '）'];
    }
    return $result['ok'] || $result['status'] === 207
        ? ['ok' => true, 'message' => '连接成功（WebDAV 可用）']
        : ['ok' => false, 'message' => '连接失败：HTTP ' . $result['status'] . ' ' . mb_substr($result['body'], 0, 120)];
}

function remote_dav_put(array $target, string $localFile, string $remoteName): array
{
    $url = remote_dav_url($target, $remoteName);
    $result = remote_http('PUT', $url, remote_dav_auth($target) + ['Content-Type' => 'application/octet-stream'], $localFile, 300);
    return $result['ok']
        ? ['ok' => true, 'message' => '已上传到 WebDAV']
        : ['ok' => false, 'message' => '上传失败：HTTP ' . $result['status'] . ' ' . mb_substr($result['body'], 0, 120)];
}

/* ------------------------------ S3 SigV4 ------------------------------ */

function remote_s3_host(array $target): string
{
    $endpoint = (string)$target['endpoint'];
    $host = parse_url($endpoint, PHP_URL_HOST) ?: $endpoint;
    return (string)$host;
}

function remote_s3_url(array $target, string $key = '', bool $list = false): string
{
    $endpoint = rtrim((string)$target['endpoint'], '/');
    if (!preg_match('#^https?://#i', $endpoint)) {
        $endpoint = 'https://' . $endpoint;
    }
    $bucket = trim((string)$target['bucket'], '/');
    $url = $endpoint . '/' . rawurlencode($bucket);
    if ($list) {
        return $url;
    }
    $dir = trim((string)$target['remote_dir'], '/');
    $path = trim($dir . '/' . $key, '/');
    return $url . '/' . remote_encode_path($path);
}

/**
 * 计算 SigV4 签名（可独立测试）
 */
function remote_s3_sigv4(
    string $method,
    string $uriPath,
    string $query,
    array $headers,
    string $payloadHash,
    string $accessKey,
    string $secretKey,
    string $region,
    string $service,
    string $amzDate
): array {
    $shortDate = substr($amzDate, 0, 8);

    $canonicalHeaders = [];
    $signedHeaderNames = [];
    foreach ($headers as $name => $value) {
        $lower = strtolower($name);
        $canonicalHeaders[$lower] = trim((string)$value);
        $signedHeaderNames[] = $lower;
    }
    ksort($canonicalHeaders);
    sort($signedHeaderNames);
    $canonicalHeaderString = '';
    foreach ($canonicalHeaders as $name => $value) {
        $canonicalHeaderString .= $name . ':' . $value . "\n";
    }
    $signedHeaders = implode(';', $signedHeaderNames);

    $canonicalRequest = $method . "\n" . $uriPath . "\n" . $query . "\n" . $canonicalHeaderString . "\n" . $signedHeaders . "\n" . $payloadHash;
    $scope = $shortDate . '/' . $region . '/' . $service . '/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);

    $kDate = hash_hmac('sha256', $shortDate, 'AWS4' . $secretKey, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    return [
        'signature' => $signature,
        'authorization' => 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature,
        'canonical_request' => $canonicalRequest,
        'string_to_sign' => $stringToSign,
    ];
}

function remote_s3_request(array $target, string $method, string $url, ?string $bodyFile = null, bool $list = false): array
{
    $parts = parse_url($url);
    $host = (string)($parts['host'] ?? '');
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $uriPath = (string)($parts['path'] ?? '/');
    $query = (string)($parts['query'] ?? '');
    $region = trim((string)$target['region']) !== '' ? (string)$target['region'] : 'us-east-1';

    $payloadHash = $bodyFile !== null && is_file($bodyFile)
        ? hash_file('sha256', $bodyFile)
        : hash('sha256', '');
    $amzDate = gmdate('Ymd\THis\Z');
    $headers = [
        'Host' => $host . $port,
        'x-amz-content-sha256' => $payloadHash,
        'x-amz-date' => $amzDate,
    ];
    if ($bodyFile !== null) {
        $headers['Content-Type'] = 'application/octet-stream';
    }
    $signed = remote_s3_sigv4(
        $method,
        $uriPath,
        $query,
        $headers,
        $payloadHash,
        (string)$target['access_key'],
        (string)$target['secret_key'],
        $region,
        's3',
        $amzDate
    );
    $sendHeaders = $headers;
    $sendHeaders['Authorization'] = $signed['authorization'];
    return remote_http($method, $url, $sendHeaders, $bodyFile, $bodyFile !== null ? 600 : 60);
}

function remote_s3_test(array $target): array
{
    $url = remote_s3_url($target, '', true) . '?list-type=2&max-keys=1&prefix=' . rawurlencode(trim((string)$target['remote_dir'], '/'));
    $result = remote_s3_request($target, 'GET', $url, null, true);
    return $result['ok']
        ? ['ok' => true, 'message' => '连接成功（S3 可用，可列出对象）']
        : ['ok' => false, 'message' => '连接失败：HTTP ' . $result['status'] . ' ' . mb_substr(strip_tags($result['body']), 0, 160) . $result['error']];
}

function remote_s3_put(array $target, string $localFile, string $remoteName): array
{
    $url = remote_s3_url($target, $remoteName);
    $result = remote_s3_request($target, 'PUT', $url, $localFile);
    return $result['ok']
        ? ['ok' => true, 'message' => '已上传到 S3']
        : ['ok' => false, 'message' => '上传失败：HTTP ' . $result['status'] . ' ' . mb_substr(strip_tags($result['body']), 0, 160)];
}

/* ------------------------------ 阿里云 OSS ------------------------------ */

function remote_oss_url(array $target, string $key = '', bool $list = false): string
{
    $endpoint = rtrim((string)$target['endpoint'], '/');
    if (!preg_match('#^https?://#i', $endpoint)) {
        $endpoint = 'https://' . preg_replace('#^https?://#i', '', $endpoint);
    }
    $bucket = trim((string)$target['bucket'], '/');
    $dir = trim((string)$target['remote_dir'], '/');
    $path = $list ? '/' : '/' . remote_encode_path(trim($dir . '/' . $key, '/'));
    return $endpoint . '/' . rawurlencode($bucket) . $path;
}

/**
 * OSS 签名：base64(hmac-sha1(secret, VERB\nContent-MD5\nContent-Type\nDate\nCanonicalizedOSSHeaders+CanonicalizedResource))
 */
function remote_oss_signature(string $method, string $contentMd5, string $contentType, string $date, string $resource, string $secretKey, array $ossHeaders = []): string
{
    $canonicalHeaders = '';
    if ($ossHeaders) {
        $normalized = [];
        foreach ($ossHeaders as $name => $value) {
            $normalized[strtolower($name)] = trim((string)$value);
        }
        ksort($normalized);
        foreach ($normalized as $name => $value) {
            $canonicalHeaders .= $name . ':' . $value . "\n";
        }
    }
    $stringToSign = $method . "\n" . $contentMd5 . "\n" . $contentType . "\n" . $date . "\n" . $canonicalHeaders . $resource;
    return base64_encode(hash_hmac('sha1', $stringToSign, $secretKey, true));
}

function remote_oss_request(array $target, string $method, string $url, ?string $bodyFile = null, bool $list = false): array
{
    $bucket = trim((string)$target['bucket'], '/');
    $dir = trim((string)$target['remote_dir'], '/');
    $date = gmdate('D, d M Y H:i:s \G\M\T');
    $contentType = $bodyFile !== null ? 'application/octet-stream' : '';
    $contentMd5 = '';
    if ($bodyFile !== null && is_file($bodyFile)) {
        $contentMd5 = base64_encode((string)hash_file('md5', $bodyFile, true));
    }
    $resource = '/' . $bucket;
    if (!$list) {
        $parts = parse_url($url);
        $resource .= (string)($parts['path'] ?? '');
    } else {
        $resource .= '/';
    }
    $signature = remote_oss_signature(
        $method,
        $contentMd5,
        $contentType,
        $date,
        $resource,
        (string)$target['secret_key']
    );
    $headers = [
        'Date' => $date,
        'Authorization' => 'OSS ' . (string)$target['access_key'] . ':' . $signature,
    ];
    if ($contentType !== '') {
        $headers['Content-Type'] = $contentType;
        $headers['Content-MD5'] = $contentMd5;
    }
    return remote_http($method, $url, $headers, $bodyFile, 600);
}

function remote_oss_test(array $target): array
{
    $url = remote_oss_url($target, '', true) . '?prefix=' . rawurlencode(trim((string)$target['remote_dir'], '/')) . '&max-keys=1';
    $result = remote_oss_request($target, 'GET', $url, null, true);
    return $result['ok']
        ? ['ok' => true, 'message' => '连接成功（OSS 可用）']
        : ['ok' => false, 'message' => '连接失败：HTTP ' . $result['status'] . ' ' . mb_substr(strip_tags($result['body']), 0, 160)];
}

function remote_oss_put(array $target, string $localFile, string $remoteName): array
{
    $url = remote_oss_url($target, $remoteName);
    $result = remote_oss_request($target, 'PUT', $url, $localFile);
    return $result['ok']
        ? ['ok' => true, 'message' => '已上传到 OSS']
        : ['ok' => false, 'message' => '上传失败：HTTP ' . $result['status'] . ' ' . mb_substr(strip_tags($result['body']), 0, 160)];
}

/* ------------------------------ 统一入口 ------------------------------ */

function remote_test(array $target): array
{
    switch ((string)$target['kind']) {
        case 'webdav':
            return remote_dav_test($target);
        case 'oss':
            return remote_oss_test($target);
        case 's3':
        default:
            return remote_s3_test($target);
    }
}

/**
 * 把本地备份文件上传到目标，返回结果并记录
 */
function remote_upload(array $target, string $localFile): array
{
    if (!is_file($localFile)) {
        return ['ok' => false, 'message' => '本地文件不存在：' . $localFile];
    }
    $name = basename($localFile);
    switch ((string)$target['kind']) {
        case 'webdav':
            $result = remote_dav_put($target, $localFile, $name);
            break;
        case 'oss':
            $result = remote_oss_put($target, $localFile, $name);
            break;
        case 's3':
        default:
            $result = remote_s3_put($target, $localFile, $name);
            break;
    }
    execute(
        'UPDATE ' . t('remote_targets') . ' SET last_result = ?, last_at = ? WHERE id = ?',
        [mb_substr(($result['ok'] ? '成功：' : '失败：') . $result['message'], 0, 255), now(), (int)$target['id']]
    );
    if ($result['ok']) {
        log_action('异地备份上传', $name . ' → ' . $target['name'], 'info');
    }
    return $result;
}
