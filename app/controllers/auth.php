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

    if (!attempt_login($username, $password, client_ip())) {
        usleep(400000);
        flash('error', '账号或密码错误');
        redirect('/login');
    }

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
