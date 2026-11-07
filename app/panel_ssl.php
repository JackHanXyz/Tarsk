<?php
declare(strict_types=1);

/**
 * 面板自身 SSL：由托管 Nginx 以 HTTPS 反向代理到面板端口
 */

function panel_ssl_dir(): string
{
    $dir = storage_path('ssl/panel');
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function panel_ssl_selfsign(string $certFile, string $keyFile): array
{
    if (!function_exists('openssl_pkey_new')) {
        return ['ok' => false, 'message' => '当前 PHP 未启用 OpenSSL 扩展'];
    }
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    if (!$key) {
        return ['ok' => false, 'message' => '私钥生成失败'];
    }

    $cnf = storage_path('tmp/panel_ssl_openssl.cnf');
    file_put_contents($cnf, "[req]\ndistinguished_name = dn\nreq_extensions = v3_req\n[dn]\n[v3_req]\nsubjectAltName = DNS:localhost,DNS:tarsk.local,IP:127.0.0.1\n");
    $csr = openssl_csr_new(
        ['commonName' => 'Tarsk Panel', 'organizationName' => 'Tarsk'],
        $key,
        ['digest_alg' => 'sha256', 'config' => $cnf, 'req_extensions' => 'v3_req']
    );
    if (!$csr) {
        @unlink($cnf);
        return ['ok' => false, 'message' => 'CSR 生成失败'];
    }
    $cert = openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256', 'config' => $cnf, 'x509_extensions' => 'v3_req']);
    @unlink($cnf);
    if (!$cert) {
        return ['ok' => false, 'message' => '证书签发失败'];
    }
    openssl_x509_export_to_file($cert, $certFile);
    openssl_pkey_export_to_file($key, $keyFile);
    return ['ok' => true, 'message' => '证书已生成'];
}

function panel_ssl_conf_path(): string
{
    return rtrim(nginx_vhost_dir(), '/\\') . '/00-panel-ssl.conf';
}

function panel_ssl_apply(int $port): array
{
    if (!managed_nginx_installed()) {
        return ['ok' => false, 'message' => '请先在软件商店安装托管 Nginx'];
    }
    $dir = panel_ssl_dir();
    $certFile = str_replace('\\', '/', $dir . '/cert.pem');
    $keyFile = str_replace('\\', '/', $dir . '/privkey.pem');
    if (!is_file($certFile) || !is_file($keyFile)) {
        $gen = panel_ssl_selfsign($certFile, $keyFile);
        if (!$gen['ok']) {
            return $gen;
        }
    }

    $panelPort = (int)setting('panel_port', 8888);
    $content = "# 由 Tarsk 自动生成 · 面板 SSL 反代\n"
        . "server {\n"
        . "    listen " . $port . " ssl;\n"
        . "    server_name _;\n"
        . "    ssl_certificate \"" . $certFile . "\";\n"
        . "    ssl_certificate_key \"" . $keyFile . "\";\n"
        . "    ssl_protocols TLSv1.2 TLSv1.3;\n"
        . "    location / {\n"
        . "        proxy_pass http://127.0.0.1:" . $panelPort . ";\n"
        . "        proxy_http_version 1.1;\n"
        . "        proxy_set_header Host \$host;\n"
        . "        proxy_set_header X-Real-IP \$remote_addr;\n"
        . "        proxy_set_header X-Forwarded-Proto https;\n"
        . "        proxy_read_timeout 3600s;\n"
        . "    }\n"
        . "}\n";
    if (@file_put_contents(panel_ssl_conf_path(), $content) === false) {
        return ['ok' => false, 'message' => 'Nginx 配置写入失败'];
    }

    $state = runtime_state('nginx');
    if ($state['running']) {
        if (!runtime_nginx_reload()) {
            return ['ok' => false, 'message' => 'Nginx 配置重载失败，请检查错误日志'];
        }
    } else {
        $start = runtime_start('nginx');
        if (!$start['ok']) {
            return ['ok' => false, 'message' => $start['message']];
        }
    }
    return ['ok' => true, 'message' => '面板 SSL 已开启：https://127.0.0.1:' . $port . ' （自签证书，浏览器提示属正常）'];
}

function panel_ssl_remove_vhost(): void
{
    $conf = panel_ssl_conf_path();
    if (is_file($conf)) {
        @unlink($conf);
        if (runtime_state('nginx')['running']) {
            runtime_nginx_reload();
        }
    }
}
