<?php
declare(strict_types=1);

function settings_page(): void
{
    $user = current_user();
    $mysql = mysql_server_info();
    $diskFree = PHP_OS_FAMILY === 'Windows'
        ? @disk_free_space((string)(getenv('SystemDrive') ?: 'C:') . '/')
        : @disk_free_space('/');

    render('settings', [
        'title' => '设置',
        'active' => 'settings',
        'user' => $user,
        'mysql' => $mysql,
        'info' => [
            'platform' => PHP_OS_FAMILY,
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'hostname' => gethostname(),
            'version' => config('app.version', '1.0.0'),
            'sites_root' => sites_root(),
            'backup_path' => backup_path(),
            'storage' => storage_path(),
            'nginx' => find_nginx_bin() ?: '未检测到',
            'mysql_bin' => find_mysql_bin() ?: '未检测到',
            'disk_free' => (int)$diskFree,
            'prefix' => db_prefix(),
            'db_name' => config('database.name'),
        ],
        'settings' => settings_all(true),
    ], 'app');
}

function settings_save(): void
{
    $sitesRoot = trim((string)($_POST['sites_root'] ?? ''));
    $backupPath = trim((string)($_POST['backup_path'] ?? ''));

    if ($sitesRoot !== '' && !preg_match('/^[A-Za-z]:[\\\\\/]|^[\/]/', $sitesRoot)) {
        flash('error', '站点根目录需为绝对路径，例如 D:/wwwroot');
        redirect('/settings');
    }
    if ($backupPath !== '' && !preg_match('/^[A-Za-z]:[\\\\\/]|^[\/]/', $backupPath)) {
        flash('error', '备份目录需为绝对路径');
        redirect('/settings');
    }

    $fields = [
        'panel_name' => fn($v) => trim($v) !== '' ? trim($v) : 'Tarsk',
        'sites_root' => fn($v) => trim($v),
        'backup_path' => fn($v) => trim($v),
        'refresh_interval' => fn($v) => max(1, min(30, (int)$v)),
        'monitor_retention_hours' => fn($v) => max(6, min(720, (int)$v)),
        'mysql_admin_user' => fn($v) => trim($v),
        'mysql_admin_pass' => fn($v) => trim($v),
        'login_notice' => fn($v) => mb_substr(trim($v), 0, 200),
        'nginx_port' => fn($v) => max(1, min(65535, (int)$v)),
        'php_port' => fn($v) => max(1, min(65535, (int)$v)),
        'redis_port' => fn($v) => max(1, min(65535, (int)$v)),
        'backup_retention' => fn($v) => max(1, min(60, (int)$v)),
        'cert_renew_days' => fn($v) => max(1, min(60, (int)$v)),
    ];
    foreach ($fields as $key => $sanitize) {
        if (array_key_exists($key, $_POST)) {
            setting_set($key, $sanitize($_POST[$key]));
        }
    }
    cache_forget('metrics');

    log_action('更新设置', '面板设置已保存');
    flash('success', '设置已保存');
    redirect('/settings');
}

function settings_password(): void
{
    $user = require_login();
    $current = (string)($_POST['current_password'] ?? '');
    $next = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (!password_verify($current, $user['password_hash'])) {
        flash('error', '当前密码不正确');
        redirect('/settings');
    }
    if (strlen($next) < 8) {
        flash('error', '新密码至少 8 位');
        redirect('/settings');
    }
    if ($next !== $confirm) {
        flash('error', '两次输入的新密码不一致');
        redirect('/settings');
    }

    execute(
        'UPDATE ' . t('users') . ' SET password_hash = ? WHERE id = ?',
        [password_hash($next, PASSWORD_DEFAULT), $user['id']]
    );
    log_action('修改密码', '管理员密码已更新', 'warning');
    flash('success', '密码已更新，请重新登录');
    $_SESSION = [];
    session_destroy();
    redirect('/login');
}

/* ------------------------------- 安全设置 ------------------------------- */

function settings_security_save(): void
{
    require_login();
    $entrance = trim((string)($_POST['security_entrance'] ?? ''));
    $recycle = (string)($_POST['recycle_enabled'] ?? '0') === '1' ? '1' : '0';

    if ($entrance !== '' && !preg_match('/^[A-Za-z0-9_-]{4,32}$/', $entrance)) {
        flash('error', '安全入口须为 4-32 位字母、数字、短横线或下划线');
        redirect('/settings');
    }
    setting_set('security_entrance', $entrance);
    setting_set('recycle_enabled', $recycle);
    log_action('安全设置', '安全入口：' . ($entrance !== '' ? '/' . $entrance : '关闭') . '；回收站：' . ($recycle === '1' ? '开' : '关'), 'warning');
    flash('success', $entrance !== ''
        ? '安全设置已保存，入口地址：' . url('/' . $entrance) . '（请牢记）'
        : '安全设置已保存');
    redirect('/settings');
}

function settings_2fa_prepare(): void
{
    $user = require_login();
    $secret = totp_generate_secret();
    $_SESSION['tfa_setup_secret'] = $secret;
    json_response([
        'ok' => true,
        'secret' => $secret,
        'otpauth' => totp_otpauth_url($secret, (string)$user['username'], (string)setting('panel_name', 'Tarsk')),
    ]);
}

function settings_2fa_enable(): void
{
    $user = require_login();
    $secret = (string)($_SESSION['tfa_setup_secret'] ?? '');
    $code = trim((string)($_POST['code'] ?? ''));
    if ($secret === '') {
        flash('error', '请先生成密钥');
        redirect('/settings');
    }
    if (!totp_verify($secret, $code)) {
        flash('error', '验证码不正确，两步验证未开启');
        redirect('/settings');
    }
    execute('UPDATE ' . t('users') . ' SET totp_secret = ?, totp_enabled = 1 WHERE id = ?', [$secret, $user['id']]);
    unset($_SESSION['tfa_setup_secret']);
    log_action('开启两步验证', (string)$user['username'], 'warning');
    flash('success', '两步验证已开启，下次登录需输入动态验证码');
    redirect('/settings');
}

function settings_2fa_disable(): void
{
    $user = require_login();
    $code = trim((string)($_POST['code'] ?? ''));
    if (!totp_verify((string)($user['totp_secret'] ?? ''), $code)) {
        flash('error', '验证码不正确，无法关闭');
        redirect('/settings');
    }
    execute("UPDATE " . t('users') . " SET totp_secret = '', totp_enabled = 0 WHERE id = ?", [$user['id']]);
    log_action('关闭两步验证', (string)$user['username'], 'warning');
    flash('success', '两步验证已关闭');
    redirect('/settings');
}

/* ------------------------------- 面板 SSL ------------------------------- */

function settings_panel_ssl(): void
{
    require_login();
    $enable = (string)($_POST['enabled'] ?? '0') === '1';
    $port = max(1, min(65535, (int)($_POST['port'] ?? 8443)));
    setting_set('panel_ssl_port', (string)$port);

    if (!$enable) {
        setting_set('panel_ssl_enabled', '0');
        panel_ssl_remove_vhost();
        log_action('面板 SSL', '已关闭', 'warning');
        flash('success', '面板 SSL 已关闭');
        redirect('/settings');
    }

    $result = panel_ssl_apply($port);
    if ($result['ok']) {
        setting_set('panel_ssl_enabled', '1');
        log_action('面板 SSL', '已开启，端口 ' . $port, 'warning');
        flash('success', $result['message']);
    } else {
        flash('error', $result['message']);
    }
    redirect('/settings');
}
