<?php
declare(strict_types=1);

function settings_page(): void
{
    $user = current_user();
    $mysql = mysql_server_info();
    $diskFree = PHP_OS_FAMILY === 'Windows'
        ? @disk_free_space((string)(getenv('SystemDrive') ?: 'C:') . '/')
        : @disk_free_space('/');

    // 恢复码明文只在生成后展示一次
    $recoveryNew = $_SESSION['tfa_recovery_new'] ?? [];
    unset($_SESSION['tfa_recovery_new']);

    render('settings', [
        'title' => '设置',
        'active' => 'settings',
        'user' => $user,
        'mysql' => $mysql,
        'recoveryNew' => $recoveryNew,
        'recoveryLeft' => $user ? totp_recovery_remaining((int)$user['id']) : 0,
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
        'locked' => login_locked_list(),
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
    $allowlist = trim((string)($_POST['panel_ip_allowlist'] ?? ''));
    $maxFail = max(3, min(20, (int)($_POST['login_max_fail'] ?? 5)));
    $lockMinutes = max(1, min(1440, (int)($_POST['login_lock_minutes'] ?? 15)));

    if ($entrance !== '' && !preg_match('/^[A-Za-z0-9_-]{4,32}$/', $entrance)) {
        flash('error', '安全入口须为 4-32 位字母、数字、短横线或下划线');
        redirect('/settings');
    }
    $rules = [];
    foreach (preg_split('/[\s,;]+/', $allowlist) as $rule) {
        $rule = trim($rule);
        if ($rule === '') {
            continue;
        }
        if (!preg_match('#^(?:\d{1,3}\.){1,3}(?:\d{1,3}|\*)|\d{1,3}(?:\.\d{1,3}){3}/\d{1,2}|[A-Fa-f0-9:]+#', $rule)) {
            flash('error', 'IP 白名单格式不正确：' . $rule . '（支持 1.2.3.4、1.2.3.*、1.2.3.0/24）');
            redirect('/settings');
        }
        $rules[] = $rule;
    }
    // 保存前确认当前 IP 仍在白名单内，避免把自己锁在门外
    $newList = implode(',', $rules);
    if ($newList !== '' && !security_ip_allowed_current($newList)) {
        flash('error', '当前访问 IP（' . client_ip() . '）不在新白名单内，已拒绝保存以免失联');
        redirect('/settings');
    }

    setting_set('security_entrance', $entrance);
    setting_set('recycle_enabled', $recycle);
    setting_set('panel_ip_allowlist', $newList);
    setting_set('login_max_fail', (string)$maxFail);
    setting_set('login_lock_minutes', (string)$lockMinutes);
    log_action(
        '安全设置',
        '安全入口：' . ($entrance !== '' ? '/' . $entrance : '关闭')
        . '；回收站：' . ($recycle === '1' ? '开' : '关')
        . '；IP 白名单：' . ($newList !== '' ? $newList : '不限')
        . '；登录失败锁定：' . $maxFail . ' 次 / ' . $lockMinutes . ' 分钟',
        'warning'
    );
    flash('success', $entrance !== ''
        ? '安全设置已保存，入口地址：' . url('/' . $entrance) . '（请牢记）'
        : '安全设置已保存');
    redirect('/settings');
}

/**
 * 用候选白名单判断当前 IP 是否可通过（保存前自检）
 */
function security_ip_allowed_current(string $candidateList): bool
{
    $ip = client_ip();
    foreach (preg_split('/[\s,;]+/', $candidateList) as $rule) {
        if ($rule !== '' && security_ip_match($ip, $rule)) {
            return true;
        }
    }
    return false;
}

function settings_notify_save(): void
{
    require_login();
    $channels = array_values(array_intersect(
        array_map('strval', (array)($_POST['channels'] ?? [])),
        ['mail', 'webhook']
    ));

    $smtpPort = max(1, min(65535, (int)($_POST['smtp_port'] ?? 465)));
    $secure = (string)($_POST['smtp_secure'] ?? 'ssl');
    if (!in_array($secure, ['ssl', 'tls', 'none'], true)) {
        $secure = 'ssl';
    }
    $webhook = trim((string)($_POST['notify_webhook'] ?? ''));
    if ($webhook !== '' && !preg_match('#^https?://#', $webhook)) {
        flash('error', 'Webhook 地址必须以 http:// 或 https:// 开头');
        redirect('/settings');
    }

    $fields = [
        'smtp_host' => trim((string)($_POST['smtp_host'] ?? '')),
        'smtp_port' => (string)$smtpPort,
        'smtp_user' => trim((string)($_POST['smtp_user'] ?? '')),
        'smtp_pass' => trim((string)($_POST['smtp_pass'] ?? '')),
        'smtp_secure' => $secure,
        'smtp_from' => trim((string)($_POST['smtp_from'] ?? '')),
        'smtp_to' => trim((string)($_POST['smtp_to'] ?? '')),
        'notify_webhook' => $webhook,
        'alert_cpu' => (string)max(1, min(100, (int)($_POST['alert_cpu'] ?? 90))),
        'alert_mem' => (string)max(1, min(100, (int)($_POST['alert_mem'] ?? 90))),
        'alert_disk' => (string)max(1, min(100, (int)($_POST['alert_disk'] ?? 90))),
        'alert_cooldown' => (string)max(5, min(1440, (int)($_POST['alert_cooldown'] ?? 30))),
        'self_heal_enabled' => (string)($_POST['self_heal_enabled'] ?? '0') === '1' ? '1' : '0',
        'self_heal_cooldown' => (string)max(60, min(86400, (int)($_POST['self_heal_cooldown'] ?? 600))),
        'self_heal_max_hour' => (string)max(1, min(20, (int)($_POST['self_heal_max_hour'] ?? 3))),
    ];
    foreach ($fields as $key => $value) {
        setting_set($key, $value);
    }
    setting_set('notify_channels', implode(',', $channels));

    log_action('更新通知设置', '渠道：' . ($channels ? implode('/', $channels) : '未启用'), 'info');
    flash('success', '通知设置已保存');
    redirect('/settings');
}

function settings_notify_test(): void
{
    require_login();
    $channels = notify_channels();
    if (!$channels) {
        flash('warning', '未启用任何通知渠道，请先勾选并保存');
        redirect('/settings');
    }
    $results = notify('测试通知', '这是一条来自 Tarsk 面板的测试消息，收到即表示通知渠道配置正确。', 'info');
    $parts = [];
    $ok = false;
    foreach ($results as $channel => $result) {
        $parts[] = ($channel === 'mail' ? '邮件' : 'Webhook') . '：' . $result['message'];
        $ok = $ok || $result['ok'];
    }
    log_action('测试通知', implode('；', $parts), $ok ? 'info' : 'warning');
    flash($ok ? 'success' : 'error', implode('；', $parts));
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
    $codes = totp_recovery_generate((int)$user['id']);
    $_SESSION['tfa_recovery_new'] = $codes['plain'];
    log_action('开启两步验证', (string)$user['username'], 'warning');
    flash('success', '两步验证已开启。下面的恢复码只显示这一次，请立刻保存到安全的地方。');
    redirect('/settings');
}

/**
 * 重新生成一次性恢复码（需当前动态验证码确认）
 */
function settings_2fa_recovery(): void
{
    $user = require_login();
    if ((int)($user['totp_enabled'] ?? 0) !== 1) {
        flash('error', '请先开启两步验证');
        redirect('/settings');
    }
    $code = trim((string)($_POST['code'] ?? ''));
    if (!totp_verify((string)($user['totp_secret'] ?? ''), $code)) {
        flash('error', '验证码不正确，未重新生成恢复码');
        redirect('/settings');
    }
    $codes = totp_recovery_generate((int)$user['id']);
    $_SESSION['tfa_recovery_new'] = $codes['plain'];
    log_action('重新生成恢复码', (string)$user['username'], 'warning');
    flash('success', '已生成新的恢复码，旧恢复码全部失效。');
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
