<?php
declare(strict_types=1);

/**
 * SSL 证书管理
 */
function ssl_page(): void
{
    $certs = fetch_all('SELECT c.*, s.name AS site_name FROM ' . t('certs') . ' c LEFT JOIN ' . t('sites') . ' s ON s.id = c.site_id ORDER BY c.id DESC');
    foreach ($certs as &$cert) {
        $cert['days_left'] = $cert['not_after'] ? (int)floor((strtotime($cert['not_after']) - time()) / 86400) : null;
        $cert['exists'] = is_file((string)$cert['cert_path']) && is_file((string)$cert['key_path']);
    }
    unset($cert);

    $sites = fetch_all('SELECT id, name, domain FROM ' . t('sites') . ' ORDER BY id DESC');

    render('ssl', [
        'title' => 'SSL 证书',
        'active' => 'ssl',
        'certs' => $certs,
        'sites' => $sites,
        'openssl_ext' => extension_loaded('openssl'),
        'openssl_cli' => command_exists('openssl'),
        'ssl_dir' => storage_path('ssl'),
    ], 'app');
}

function ssl_generate(): void
{
    $siteId = (int)($_POST['site_id'] ?? 0);
    $domain = trim((string)($_POST['domain'] ?? ''));
    $days = (int)($_POST['days'] ?? 365);
    $days = max(1, min(3650, $days));

    $site = $siteId > 0 ? fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$siteId]) : null;
    if ($domain === '' && $site) {
        $domain = (string)($site['domain'] !== '' ? $site['domain'] : $site['name'] . '.local');
    }
    if ($domain === '') {
        flash('error', '请填写域名或选择站点');
        redirect('/ssl');
    }
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-]{1,190}$/', $domain)) {
        flash('error', '域名格式不合法');
        redirect('/ssl');
    }

    if (!extension_loaded('openssl')) {
        flash('error', '当前 PHP 未启用 openssl 扩展，无法生成证书');
        redirect('/ssl');
    }

    $configFile = storage_path('ssl/openssl.cnf');
    $config = "[req]\ndistinguished_name=dn\nreq_extensions=v3_req\n[dn]\n[v3_req]\nsubjectAltName=@alt\n[alt]\nDNS.1=" . $domain . "\n";
    file_put_contents($configFile, $config);

    $dn = [
        'commonName' => $domain,
        'organizationName' => 'Tarsk',
        'organizationalUnitName' => 'Tarsk Panel',
        'countryName' => 'CN',
        'stateOrProvinceName' => 'Local',
        'localityName' => 'Local',
    ];
    $options = ['config' => $configFile, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

    $key = openssl_pkey_new($options);
    if ($key === false) {
        $error = openssl_error_string() ?: '密钥生成失败';
        flash('error', '生成失败：' . $error);
        redirect('/ssl');
    }
    $csr = openssl_csr_new($dn, $key, ['config' => $configFile, 'digest_alg' => 'sha256', 'req_extensions' => 'v3_req']);
    if ($csr === false) {
        flash('error', 'CSR 生成失败：' . (openssl_error_string() ?: '未知错误'));
        redirect('/ssl');
    }
    $cert = openssl_csr_sign($csr, null, $key, $days, ['config' => $configFile, 'digest_alg' => 'sha256', 'x509_extensions' => 'v3_req']);
    if ($cert === false) {
        flash('error', '证书签发失败：' . (openssl_error_string() ?: '未知错误'));
        redirect('/ssl');
    }

    $certPem = '';
    $keyPem = '';
    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($key, $keyPem, null, ['config' => $configFile]);

    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $domain) . '_' . date('YmdHis');
    $certPath = storage_path('ssl/' . $safeName . '.crt');
    $keyPath = storage_path('ssl/' . $safeName . '.key');
    file_put_contents($certPath, $certPem);
    file_put_contents($keyPath, $keyPem);

    $parsed = openssl_x509_parse($certPem) ?: [];
    execute(
        'INSERT INTO ' . t('certs') . ' (site_id, domain, cert_path, key_path, issuer, not_before, not_after, source, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
        [
            $siteId > 0 ? $siteId : null,
            $domain,
            $certPath,
            $keyPath,
            (string)($parsed['issuer']['CN'] ?? 'Tarsk Self-Signed'),
            date('Y-m-d H:i:s', (int)($parsed['validFrom_time_t'] ?? time())),
            date('Y-m-d H:i:s', (int)($parsed['validTo_time_t'] ?? (time() + $days * 86400))),
            'self',
            now(),
        ]
    );

    if ($site) {
        execute('UPDATE ' . t('sites') . ' SET ssl_enabled = 1 WHERE id = ?', [$siteId]);
        write_site_vhost(fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$siteId]));
    }

    log_action('签发自签名证书', $domain . ' · ' . $days . ' 天');
    flash('success', '证书已生成：' . $certPath);
    redirect('/ssl');
}

function ssl_upload(): void
{
    $domain = trim((string)($_POST['domain'] ?? ''));
    $certText = trim((string)($_POST['cert'] ?? ''));
    $keyText = trim((string)($_POST['key'] ?? ''));
    $siteId = (int)($_POST['site_id'] ?? 0);

    if ($domain === '' || $certText === '' || $keyText === '') {
        flash('error', '域名、证书与私钥均不能为空');
        redirect('/ssl');
    }
    if (!extension_loaded('openssl')) {
        flash('error', '当前 PHP 未启用 openssl 扩展');
        redirect('/ssl');
    }

    $parsed = openssl_x509_parse($certText);
    if (!is_array($parsed)) {
        flash('error', '证书内容无法解析，请粘贴 PEM 格式证书');
        redirect('/ssl');
    }
    $key = openssl_pkey_get_private($keyText);
    if ($key === false) {
        flash('error', '私钥内容无法解析');
        redirect('/ssl');
    }
    if (!openssl_x509_check_private_key($certText, $key)) {
        flash('error', '证书与私钥不匹配');
        redirect('/ssl');
    }

    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $domain) . '_' . date('YmdHis');
    $certPath = storage_path('ssl/' . $safeName . '.crt');
    $keyPath = storage_path('ssl/' . $safeName . '.key');
    file_put_contents($certPath, $certText . "\n");
    file_put_contents($keyPath, $keyText . "\n");

    execute(
        'INSERT INTO ' . t('certs') . ' (site_id, domain, cert_path, key_path, issuer, not_before, not_after, source, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
        [
            $siteId > 0 ? $siteId : null,
            $domain,
            $certPath,
            $keyPath,
            (string)($parsed['issuer']['CN'] ?? ''),
            date('Y-m-d H:i:s', (int)($parsed['validFrom_time_t'] ?? time())),
            date('Y-m-d H:i:s', (int)($parsed['validTo_time_t'] ?? time())),
            'upload',
            now(),
        ]
    );

    if ($siteId > 0) {
        execute('UPDATE ' . t('sites') . ' SET ssl_enabled = 1 WHERE id = ?', [$siteId]);
        write_site_vhost(fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$siteId]));
    }

    log_action('上传证书', $domain);
    flash('success', '证书已导入并保存');
    redirect('/ssl');
}

function ssl_letsencrypt(): void
{
    require_login();
    @set_time_limit(180);

    $siteId = (int)($_POST['site_id'] ?? 0);
    $email = trim((string)($_POST['email'] ?? ''));
    $site = $siteId > 0 ? fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$siteId]) : null;
    if (!$site) {
        flash('error', '请选择站点');
        redirect('/ssl');
    }
    $domain = trim((string)$site['domain']);
    if ($domain === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-]{1,190}$/', $domain)) {
        flash('error', '站点未绑定有效域名，无法申请 Let\'s Encrypt 证书');
        redirect('/ssl');
    }
    if ($email === '') {
        $email = 'admin@' . $domain;
    }
    $sitePath = str_replace('\\', '/', (string)$site['path']);
    if (!is_dir($sitePath)) {
        flash('error', '站点目录不存在');
        redirect('/ssl');
    }

    if (!managed_nginx_installed() || !runtime_state('nginx')['running']) {
        flash('error', '请先在软件商店安装并启动托管 Nginx（HTTP-01 验证需要）');
        redirect('/ssl');
    }
    if (active_web_port() !== 80) {
        flash('warning', '提示：Let\'s Encrypt 要求域名的 80 端口可访问本站点目录；当前站点端口为 ' . active_web_port() . '，若有端口映射可忽略');
    }

    $result = acme_issue([$domain], $sitePath, $email);
    if (!$result['ok']) {
        flash('error', '签发失败：' . $result['message']);
        redirect('/ssl');
    }

    execute(
        'INSERT INTO ' . t('certs') . ' (site_id, domain, cert_path, key_path, issuer, not_before, not_after, source, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
        [$siteId, $domain, $result['cert'], $result['key'], "Let's Encrypt", now(), $result['expires'], 'letsencrypt', now()]
    );
    execute('UPDATE ' . t('sites') . ' SET ssl_enabled = 1 WHERE id = ?', [$siteId]);
    write_site_vhost(fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$siteId]));

    log_action('签发 Let\'s Encrypt 证书', $domain);
    flash('success', 'Let\'s Encrypt 证书签发成功，已自动配置到站点（有效期至 ' . $result['expires'] . '）');
    redirect('/ssl');
}

function ssl_delete(int $id): void
{
    $cert = fetch_one('SELECT * FROM ' . t('certs') . ' WHERE id = ?', [$id]);
    if (!$cert) {
        flash('error', '证书不存在');
        redirect('/ssl');
    }

    $sslDir = str_replace('\\', '/', storage_path('ssl'));
    foreach (['cert_path', 'key_path'] as $field) {
        $path = str_replace('\\', '/', (string)$cert[$field]);
        if (str_starts_with($path, $sslDir) && is_file($path)) {
            @unlink($path);
        }
    }

    execute('DELETE FROM ' . t('certs') . ' WHERE id = ?', [$id]);
    if (!empty($cert['site_id'])) {
        execute('UPDATE ' . t('sites') . ' SET ssl_enabled = 0 WHERE id = ?', [$cert['site_id']]);
        $site = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$cert['site_id']]);
        if ($site) {
            write_site_vhost($site);
        }
    }

    log_action('删除证书', (string)$cert['domain'], 'danger');
    flash('success', '证书已删除');
    redirect('/ssl');
}
