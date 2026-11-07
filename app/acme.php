<?php declare(strict_types=1);
/**
 * Tarsk - Let's Encrypt ACME v2 客户端模块（HTTP-01 验证）
 *
 * 纯原生 PHP 8.x 实现，无 Composer 依赖。
 * 依赖扩展：openssl（必需）、curl（可选，缺失时使用 stream 兜底）。
 * 仅调用框架函数 storage_path() 与 log_action()（由调用方环境提供）。
 */

/** 内部状态：当前使用的 ACME 目录 URL 与账户信息 */
function acme_state(?array $set = null): array
{
    static $state = ['directory' => '', 'accountUrl' => '', 'nonce' => ''];
    if ($set !== null) {
        $state = array_merge($state, $set);
    }
    return $state;
}

/** base64url 编码（无填充） */
function acme_b64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * 发送 HTTP 请求（优先 cURL，缺失时用 stream_context 兜底）
 * @return array{status:int,headers:array<string,string>,body:string}
 */
function acme_http_request(string $method, string $url, ?string $body = null, array $headers = []): array
{
    $method = strtoupper($method);
    $headerLines = [];
    foreach ($headers as $k => $v) {
        $headerLines[] = $k . ': ' . $v;
    }

    if (extension_loaded('curl')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_USERAGENT => 'Tarsk-ACME/1.0',
        ]);
        if ($body !== null && $method !== 'GET' && $method !== 'HEAD') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['status' => 0, 'headers' => [], 'body' => 'cURL error: ' . $err];
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $rawHeaders = substr($raw, 0, $headerSize);
        $respBody = (string)substr($raw, $headerSize);
        // 处理多次响应头（如 100-continue / 重定向）
        $blocks = preg_split("/\r\n\r\n/", trim($rawHeaders));
        $lastBlock = (string)end($blocks);
        return ['status' => $status, 'headers' => acme_parse_headers($lastBlock), 'body' => $respBody];
    }

    // stream 兜底
    $opts = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'timeout' => 30,
            'ignore_errors' => true,
            'user_agent' => 'Tarsk-ACME/1.0',
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ];
    if ($body !== null && $method !== 'GET' && $method !== 'HEAD') {
        $opts['http']['content'] = $body;
    }
    $ctx = stream_context_create($opts);
    $respBody = @file_get_contents($url, false, $ctx);
    $respHeaders = [];
    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        $statusLine = (string)($http_response_header[0] ?? '');
        if (preg_match('#HTTP/\S+\s+(\d{3})#', $statusLine, $m)) {
            $status = (int)$m[1];
        }
        $respHeaders = acme_parse_headers(implode("\r\n", array_slice($http_response_header, 1)));
    }
    if ($respBody === false) {
        $respBody = '';
    }
    return ['status' => $status, 'headers' => $respHeaders, 'body' => $respBody];
}

/** 解析响应头为小写键数组 */
function acme_parse_headers(string $raw): array
{
    $out = [];
    foreach (preg_split('/\r\n|\n|\r/', $raw) as $line) {
        $pos = strpos($line, ':');
        if ($pos !== false) {
            $out[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
        }
    }
    return $out;
}

/** 获取（或生成）ACME 账户私钥 PEM，存放于 storage/ssl/account_key.pem */
function acme_account_key(): string
{
    $path = storage_path('ssl/account_key.pem');
    if (is_file($path)) {
        $pem = (string)file_get_contents($path);
        if ($pem !== '') {
            return $pem;
        }
    }
    $res = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
    ]);
    if ($res === false) {
        throw new RuntimeException('生成账户私钥失败: ' . (string)openssl_error_string());
    }
    $pem = '';
    if (!openssl_pkey_export($res, $pem)) {
        throw new RuntimeException('导出账户私钥失败: ' . (string)openssl_error_string());
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    file_put_contents($path, $pem, LOCK_EX);
    @chmod($path, 0600);
    return $pem;
}

/** 账户 JWK（RSA 公钥的 e/n 分量） */
function acme_account_jwk(): array
{
    $res = openssl_pkey_get_private(acme_account_key());
    if ($res === false) {
        throw new RuntimeException('解析账户私钥失败');
    }
    $detail = openssl_pkey_get_details($res);
    if ($detail === false || !isset($detail['rsa'])) {
        throw new RuntimeException('读取账户公钥参数失败');
    }
    return [
        'e' => acme_b64url($detail['rsa']['e']),
        'kty' => 'RSA',
        'n' => acme_b64url($detail['rsa']['n']),
    ];
}

/** RFC 7638 JWK thumbprint（SHA-256，base64url） */
function acme_jwk_thumbprint(): string
{
    $jwk = acme_account_jwk();
    // 按键名字典序序列化
    $json = json_encode($jwk, JSON_UNESCAPED_SLASHES);
    return acme_b64url(hash('sha256', (string)$json, true));
}

/**
 * 构造并发送带 JWS 签名的 ACME 请求
 * @param mixed $payload null 表示 POST-as-GET；'' 表示空 JSON 对象 {}；其余为数组
 * @return array{status:int,headers:array<string,string>,body:string,json:?array}
 */
function acme_jws(string $url, string $nonce, $payload, string $accountUrl = ''): array
{
    $protected = [
        'alg' => 'RS256',
        'nonce' => $nonce,
        'url' => $url,
    ];
    if ($accountUrl === '') {
        $protected['jwk'] = acme_account_jwk();
    } else {
        $protected['kid'] = $accountUrl;
    }

    if ($payload === null) {
        $payloadB64 = ''; // POST-as-GET
    } elseif ($payload === '') {
        $payloadB64 = acme_b64url('{}');
    } else {
        $payloadB64 = acme_b64url((string)json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    $protectedB64 = acme_b64url((string)json_encode($protected, JSON_UNESCAPED_SLASHES));
    $signingInput = $protectedB64 . '.' . $payloadB64;

    $key = openssl_pkey_get_private(acme_account_key());
    if ($key === false) {
        throw new RuntimeException('加载账户私钥失败');
    }
    $signature = '';
    if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('JWS 签名失败: ' . (string)openssl_error_string());
    }

    $body = json_encode([
        'protected' => $protectedB64,
        'payload' => $payloadB64,
        'signature' => acme_b64url($signature),
    ], JSON_UNESCAPED_SLASHES);

    $resp = acme_http_request('POST', $url, (string)$body, ['Content-Type' => 'application/jose+json']);
    // 更新 nonce
    $newNonce = $resp['headers']['replay-nonce'] ?? '';
    if ($newNonce !== '') {
        acme_state(['nonce' => $newNonce]);
    }
    $json = null;
    $decoded = json_decode($resp['body'], true);
    if (is_array($decoded)) {
        $json = $decoded;
    }
    return ['status' => $resp['status'], 'headers' => $resp['headers'], 'body' => $resp['body'], 'json' => $json];
}

/** 获取可用 nonce（必要时向 new-nonce 端点请求） */
function acme_get_nonce(string $newNonceUrl): string
{
    $nonce = acme_state()['nonce'];
    if ($nonce !== '') {
        return $nonce;
    }
    $resp = acme_http_request('HEAD', $newNonceUrl);
    $nonce = $resp['headers']['replay-nonce'] ?? '';
    if ($nonce === '') {
        throw new RuntimeException('无法从 ACME 服务器获取 nonce');
    }
    acme_state(['nonce' => $nonce]);
    return $nonce;
}

/** 从错误响应中提取可读信息 */
function acme_error_detail(?array $json, string $fallback): string
{
    if (is_array($json)) {
        $err = $json['error'] ?? null;
        if (is_array($err)) {
            $detail = (string)($err['detail'] ?? '');
            $type = (string)($err['type'] ?? '');
            return trim($type . ' ' . $detail) !== '' ? trim($type . ' ' . $detail) : $fallback;
        }
        if (isset($json['detail'])) {
            return (string)$json['detail'];
        }
    }
    return $fallback;
}

/** 生成域名私钥与含 SAN 的 CSR（DER） */
function acme_make_csr(array $domains, string &$privkeyPem): string
{
    $keyRes = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
    ]);
    if ($keyRes === false) {
        throw new RuntimeException('生成域名私钥失败: ' . (string)openssl_error_string());
    }
    $privkeyPem = '';
    if (!openssl_pkey_export($keyRes, $privkeyPem)) {
        throw new RuntimeException('导出域名私钥失败: ' . (string)openssl_error_string());
    }

    $san = 'DNS:' . implode(',DNS:', $domains);
    $cnf = "[req]\ndistinguished_name=dn\nreq_extensions=v3_req\n[dn]\n[v3_req]\nsubjectAltName={$san}\n";
    $cnfPath = tempnam(sys_get_temp_dir(), 'acme_cnf_');
    if ($cnfPath === false) {
        throw new RuntimeException('创建临时 openssl 配置失败');
    }
    file_put_contents($cnfPath, $cnf);

    try {
        $csr = openssl_csr_new(
            ['commonName' => $domains[0]],
            $keyRes,
            ['digest_alg' => 'sha256'],
            ['config' => $cnfPath]
        );
    } finally {
        @unlink($cnfPath);
    }
    if ($csr === false) {
        throw new RuntimeException('生成 CSR 失败: ' . (string)openssl_error_string());
    }
    $csrPem = '';
    if (!openssl_csr_export($csr, $csrPem)) {
        throw new RuntimeException('导出 CSR 失败: ' . (string)openssl_error_string());
    }
    // PEM -> DER
    if (!preg_match('/-----BEGIN CERTIFICATE REQUEST-----(.+)-----END CERTIFICATE REQUEST-----/s', $csrPem, $m)) {
        throw new RuntimeException('解析 CSR PEM 失败');
    }
    $der = base64_decode(preg_replace('/\s+/', '', (string)$m[1]), true);
    if ($der === false) {
        throw new RuntimeException('CSR DER 解码失败');
    }
    return $der;
}

/**
 * 签发证书（Let's Encrypt ACME v2，HTTP-01 验证）
 * @param string[] $domains 域名列表（第一个为主域名）
 * @param string $webroot 站点根目录（用于写入 .well-known/acme-challenge/ 验证文件）
 * @param string $email 联系邮箱
 * @param bool $staging 是否使用 staging 环境（测试用）
 * @return array{ok:bool,message:string,cert:string,key:string,chain:string,expires:string}
 *   cert/key/chain 为保存后的文件绝对路径（storage/ssl/<主域名>/ 下），失败时为空字符串
 */
function acme_issue(array $domains, string $webroot, string $email, bool $staging = false): array
{
    $fail = static function (string $message): array {
        return ['ok' => false, 'message' => $message, 'cert' => '', 'key' => '', 'chain' => '', 'expires' => ''];
    };

    $domains = array_values(array_filter(array_map(static fn($d) => strtolower(trim((string)$d)), $domains)));
    if ($domains === []) {
        return $fail('域名列表不能为空');
    }
    $primary = $domains[0];
    if (!extension_loaded('openssl')) {
        return $fail('PHP openssl 扩展未启用');
    }

    $challengeDir = rtrim($webroot, '/\\') . DIRECTORY_SEPARATOR . '.well-known' . DIRECTORY_SEPARATOR . 'acme-challenge';
    $writtenTokens = [];
    $directoryUrl = $staging
        ? 'https://acme-staging-v02.api.letsencrypt.org/directory'
        : 'https://acme-v02.api.letsencrypt.org/directory';

    try {
        acme_state(['directory' => $directoryUrl, 'accountUrl' => '', 'nonce' => '']);

        // 1. 读取 directory
        $dirResp = acme_http_request('GET', $directoryUrl);
        $directory = json_decode($dirResp['body'], true);
        if ($dirResp['status'] !== 200 || !is_array($directory)) {
            throw new RuntimeException('获取 ACME 目录失败 (HTTP ' . $dirResp['status'] . ')');
        }
        $newNonceUrl = (string)($directory['newNonce'] ?? '');
        $newAccountUrl = (string)($directory['newAccount'] ?? '');
        $newOrderUrl = (string)($directory['newOrder'] ?? '');
        if ($newNonceUrl === '' || $newAccountUrl === '' || $newOrderUrl === '') {
            throw new RuntimeException('ACME 目录缺少必要端点');
        }

        // 2. 获取 nonce
        acme_get_nonce($newNonceUrl);

        // 3. 注册/获取账户
        $accountPayload = [
            'termsOfServiceAgreed' => true,
            'contact' => ['mailto:' . $email],
        ];
        $resp = acme_jws($newAccountUrl, acme_get_nonce($newNonceUrl), $accountPayload);
        if (!in_array($resp['status'], [200, 201], true)) {
            throw new RuntimeException('注册 ACME 账户失败: ' . acme_error_detail($resp['json'], 'HTTP ' . $resp['status']));
        }
        $accountUrl = (string)($resp['headers']['location'] ?? '');
        if ($accountUrl === '') {
            throw new RuntimeException('ACME 服务器未返回账户 URL (Location)');
        }
        acme_state(['accountUrl' => $accountUrl]);

        // 4. 创建订单
        $identifiers = [];
        foreach ($domains as $d) {
            $identifiers[] = ['type' => 'dns', 'value' => $d];
        }
        $resp = acme_jws($newOrderUrl, acme_get_nonce($newNonceUrl), ['identifiers' => $identifiers], $accountUrl);
        if ($resp['status'] !== 201 || !is_array($resp['json'])) {
            throw new RuntimeException('创建证书订单失败: ' . acme_error_detail($resp['json'], 'HTTP ' . $resp['status']));
        }
        $order = $resp['json'];
        $orderUrl = (string)($resp['headers']['location'] ?? '');
        $authzUrls = $order['authorizations'] ?? [];
        $finalizeUrl = (string)($order['finalize'] ?? '');
        if (!is_array($authzUrls) || $authzUrls === [] || $finalizeUrl === '') {
            throw new RuntimeException('订单响应缺少 authorizations 或 finalize 字段');
        }

        // 5-6. 处理每个 authorization 的 HTTP-01 验证
        $thumbprint = acme_jwk_thumbprint();
        foreach ($authzUrls as $authzUrl) {
            $resp = acme_jws((string)$authzUrl, acme_get_nonce($newNonceUrl), null, $accountUrl);
            $authz = $resp['json'];
            if (!is_array($authz)) {
                throw new RuntimeException('获取授权信息失败 (HTTP ' . $resp['status'] . ')');
            }
            if (($authz['status'] ?? '') === 'valid') {
                continue; // 已验证（如复用缓存授权）
            }

            $challenge = null;
            foreach (($authz['challenges'] ?? []) as $ch) {
                if (is_array($ch) && ($ch['type'] ?? '') === 'http-01') {
                    $challenge = $ch;
                    break;
                }
            }
            if ($challenge === null) {
                throw new RuntimeException('域名 ' . (string)($authz['identifier']['value'] ?? '?') . ' 不支持 HTTP-01 验证');
            }
            $token = (string)$challenge['token'];
            $keyAuth = $token . '.' . $thumbprint;

            if (!is_dir($challengeDir) && !mkdir($challengeDir, 0755, true)) {
                throw new RuntimeException('无法创建验证目录: ' . $challengeDir);
            }
            $tokenFile = $challengeDir . DIRECTORY_SEPARATOR . $token;
            if (file_put_contents($tokenFile, $keyAuth, LOCK_EX) === false) {
                throw new RuntimeException('无法写入验证文件: ' . $tokenFile);
            }
            $writtenTokens[] = $tokenFile;

            // 触发验证
            $resp = acme_jws((string)$challenge['url'], acme_get_nonce($newNonceUrl), '', $accountUrl);
            if (!in_array($resp['status'], [200, 202], true)) {
                throw new RuntimeException('触发 HTTP-01 验证失败: ' . acme_error_detail($resp['json'], 'HTTP ' . $resp['status']));
            }

            // 轮询授权状态（最多 10 次 × 3 秒）
            $authzOk = false;
            $lastError = '';
            for ($i = 0; $i < 10; $i++) {
                sleep(3);
                $resp = acme_jws((string)$authzUrl, acme_get_nonce($newNonceUrl), null, $accountUrl);
                $authz = $resp['json'];
                if (!is_array($authz)) {
                    continue;
                }
                $status = (string)($authz['status'] ?? '');
                if ($status === 'valid') {
                    $authzOk = true;
                    break;
                }
                if ($status === 'invalid') {
                    foreach (($authz['challenges'] ?? []) as $ch) {
                        if (is_array($ch) && isset($ch['error'])) {
                            $lastError = acme_error_detail(['error' => $ch['error']], '验证被拒绝');
                            break;
                        }
                    }
                    throw new RuntimeException('域名 ' . (string)($authz['identifier']['value'] ?? '?') . ' HTTP-01 验证失败: ' . ($lastError !== '' ? $lastError : '授权状态 invalid'));
                }
            }
            if (!$authzOk) {
                throw new RuntimeException('域名 ' . (string)($authz['identifier']['value'] ?? '?') . ' 验证超时（授权未变为 valid）');
            }
        }

        // 7. 生成域名私钥与 CSR
        $privkeyPem = '';
        $csrDer = acme_make_csr($domains, $privkeyPem);

        // 8. finalize 订单并轮询
        $resp = acme_jws($finalizeUrl, acme_get_nonce($newNonceUrl), ['csr' => acme_b64url($csrDer)], $accountUrl);
        if (!in_array($resp['status'], [200, 202], true)) {
            throw new RuntimeException('提交 CSR 失败: ' . acme_error_detail($resp['json'], 'HTTP ' . $resp['status']));
        }
        $certUrl = '';
        for ($i = 0; $i < 10; $i++) {
            $orderInfo = $resp['json'];
            if (is_array($orderInfo)) {
                $st = (string)($orderInfo['status'] ?? '');
                if ($st === 'valid' && !empty($orderInfo['certificate'])) {
                    $certUrl = (string)$orderInfo['certificate'];
                    break;
                }
                if ($st === 'invalid') {
                    throw new RuntimeException('订单变为 invalid: ' . acme_error_detail($orderInfo, '订单无效'));
                }
            }
            sleep(3);
            if ($orderUrl === '') {
                continue;
            }
            $resp = acme_jws($orderUrl, acme_get_nonce($newNonceUrl), null, $accountUrl);
        }
        if ($certUrl === '') {
            throw new RuntimeException('等待证书签发超时（订单未变为 valid）');
        }

        // 9. 下载证书链
        $resp = acme_jws($certUrl, acme_get_nonce($newNonceUrl), null, $accountUrl);
        if ($resp['status'] !== 200 || strpos($resp['body'], 'BEGIN CERTIFICATE') === false) {
            throw new RuntimeException('下载证书失败 (HTTP ' . $resp['status'] . ')');
        }
        $pemChain = $resp['body'];
        if (!preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----\s*/s', $pemChain, $matches)) {
            throw new RuntimeException('解析证书链 PEM 失败');
        }
        $certPem = trim((string)$matches[0][0]) . "\n";
        $chainPem = '';
        if (count($matches[0]) > 1) {
            $chainPem = trim(implode('', array_slice($matches[0], 1))) . "\n";
        }

        // 10. 保存证书文件并解析有效期
        $saveDir = storage_path('ssl/' . $primary);
        if (!is_dir($saveDir) && !mkdir($saveDir, 0750, true)) {
            throw new RuntimeException('无法创建证书保存目录: ' . $saveDir);
        }
        $certPath = $saveDir . DIRECTORY_SEPARATOR . 'cert.pem';
        $keyPath = $saveDir . DIRECTORY_SEPARATOR . 'privkey.pem';
        $chainPath = $saveDir . DIRECTORY_SEPARATOR . 'chain.pem';
        $fullchainPath = $saveDir . DIRECTORY_SEPARATOR . 'fullchain.pem';
        file_put_contents($certPath, $certPem, LOCK_EX);
        file_put_contents($keyPath, $privkeyPem, LOCK_EX);
        @chmod($keyPath, 0600);
        file_put_contents($chainPath, $chainPem, LOCK_EX);
        file_put_contents($fullchainPath, $certPem . $chainPem, LOCK_EX);

        $expires = '';
        $x509 = openssl_x509_parse($certPem);
        if (is_array($x509) && isset($x509['validTo_time_t'])) {
            $expires = date('Y-m-d H:i:s', (int)$x509['validTo_time_t']);
        }

        // 11. 清理验证文件
        foreach ($writtenTokens as $f) {
            @unlink($f);
        }

        // 12. 记录日志
        log_action('申请证书', $primary . ' 成功，有效期至 ' . $expires, 'info');
        return [
            'ok' => true,
            'message' => '证书签发成功',
            'cert' => $certPath,
            'key' => $keyPath,
            'chain' => $chainPath,
            'expires' => $expires,
        ];
    } catch (Throwable $e) {
        // 11. 失败同样清理验证文件
        foreach ($writtenTokens as $f) {
            @unlink($f);
        }
        // 12. 失败日志
        log_action('申请证书', $primary . ' 失败: ' . $e->getMessage(), 'danger');
        return $fail('证书签发失败: ' . $e->getMessage());
    }
}
