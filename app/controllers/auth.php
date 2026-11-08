<?php
declare(strict_types=1);

function login_page(): void
{
    if (current_user()) {
        redirect('/');
    }
    render('auth/login', [
        'title' => '登录',
        'panel_name' => setting('panel_name', config('app.name', 'Tarsk')),
        'notice' => setting('login_notice', ''),
    ], 'auth');
}

function login_submit(): void
{
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        flash('error', '请输入账号与密码');
        redirect('/login');
    }

    $ip = client_ip();
    $remaining = login_guard_remaining($ip, $username);
    if ($remaining > 0) {
        $minutes = (int)ceil($remaining / 60);
        flash('error', '尝试次数过多，请在 ' . $minutes . ' 分钟后再试');
        redirect('/login');
    }

    $row = fetch_one('SELECT * FROM ' . t('users') . ' WHERE username = ?', [$username]);
    if (!$row || !password_verify($password, $row['password_hash'])) {
        usleep(400000);
        $guard = login_guard_fail($ip, $username);
        log_action('登录失败', '账号 ' . $username . ' · 第 ' . $guard['attempts'] . ' 次', 'warning', null, $username);
        if ($guard['locked']) {
            flash('error', '密码错误次数过多，账号已锁定 ' . (int)setting('login_lock_minutes', '15') . ' 分钟');
            redirect('/login');
        }
        flash('error', '账号或密码错误，还可尝试 ' . $guard['remaining'] . ' 次');
        redirect('/login');
    }

    login_guard_reset($ip, $username);

    // 已启用两步验证：先验证动态码再完成登录
    if ((int)($row['totp_enabled'] ?? 0) === 1 && (string)($row['totp_secret'] ?? '') !== '') {
        $_SESSION['tfa_pending_uid'] = (int)$row['id'];
        redirect('/login/2fa');
    }

    complete_login($row);
}

function login_2fa_page(): void
{
    if (current_user()) {
        redirect('/');
    }
    if (empty($_SESSION['tfa_pending_uid'])) {
        redirect('/login');
    }
    render('auth/login_2fa', [
        'title' => '两步验证',
        'panel_name' => setting('panel_name', config('app.name', 'Tarsk')),
    ], 'auth');
}

function login_2fa_submit(): void
{
    $uid = (int)($_SESSION['tfa_pending_uid'] ?? 0);
    $row = $uid > 0 ? fetch_one('SELECT * FROM ' . t('users') . ' WHERE id = ?', [$uid]) : null;
    if (!$row) {
        redirect('/login');
    }
    $code = trim((string)($_POST['code'] ?? ''));
    if (!totp_verify((string)$row['totp_secret'], $code)) {
        usleep(300000);
        log_action('两步验证失败', '账号 ' . $row['username'], 'warning', (int)$row['id'], (string)$row['username']);
        flash('error', '动态验证码不正确，请重试');
        redirect('/login/2fa');
    }
    complete_login($row);
}

function complete_login(array $row): void
{
    session_regenerate_id(true);
    unset($_SESSION['tfa_pending_uid']);
    $_SESSION['uid'] = (int)$row['id'];
    execute(
        'UPDATE ' . t('users') . ' SET last_login_at = ?, last_ip = ? WHERE id = ?',
        [now(), client_ip(), $row['id']]
    );
    log_action('登录成功', '欢迎回来，' . $row['username'], 'info', (int)$row['id'], (string)$row['username']);
    redirect('/');
}

function logout(): void
{
    log_action('退出登录', '会话已结束');
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    redirect('/login');
}
