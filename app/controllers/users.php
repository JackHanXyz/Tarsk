<?php
declare(strict_types=1);

/**
 * 用户与角色管理（仅 admin）
 */

function users_page(): void
{
    $users = fetch_all('SELECT * FROM ' . t('users') . ' ORDER BY id');
    $adminCount = 0;
    foreach ($users as $user) {
        if ($user['role'] === 'admin' && ($user['status'] ?? 'enabled') === 'enabled') {
            $adminCount++;
        }
    }

    render('users', [
        'title' => '用户管理',
        'active' => 'users',
        'users' => $users,
        'roles' => authz_roles(),
        'matrix' => authz_matrix(),
        'permission_labels' => authz_permission_labels(),
        'admin_count' => $adminCount,
        'me' => (int)(current_user()['id'] ?? 0),
    ], 'app');
}

function users_create(): void
{
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $role = (string)($_POST['role'] ?? 'operator');
    if (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $username)) {
        flash('error', '用户名需 3~60 位，仅支持字母、数字、下划线、点与短横线');
        redirect('/users');
    }
    if (strlen($password) < 8) {
        flash('error', '密码至少 8 位');
        redirect('/users');
    }
    if (!array_key_exists($role, authz_roles())) {
        $role = 'operator';
    }
    if (fetch_one('SELECT id FROM ' . t('users') . ' WHERE username = ?', [$username])) {
        flash('error', '用户名已存在');
        redirect('/users');
    }
    execute(
        'INSERT INTO ' . t('users') . ' (username, password_hash, role, status, created_at) VALUES (?,?,?,?,?)',
        [$username, password_hash($password, PASSWORD_DEFAULT), $role, 'enabled', now()]
    );
    log_action('创建用户', $username . ' · ' . authz_roles()[$role], 'warning');
    flash('success', '用户 ' . $username . ' 已创建');
    redirect('/users');
}

function users_update(): void
{
    $id = (int)($_POST['id'] ?? 0);
    $user = fetch_one('SELECT * FROM ' . t('users') . ' WHERE id = ?', [$id]);
    if (!$user) {
        flash('error', '用户不存在');
        redirect('/users');
    }
    $role = (string)($_POST['role'] ?? $user['role']);
    $status = (string)($_POST['status'] ?? ($user['status'] ?? 'enabled'));
    if (!array_key_exists($role, authz_roles())) {
        $role = $user['role'];
    }
    if (!in_array($status, ['enabled', 'disabled'], true)) {
        $status = 'enabled';
    }
    $me = (int)(current_user()['id'] ?? 0);
    if ($id === $me && ($role !== 'admin' || $status !== 'enabled')) {
        flash('error', '不能降低或停用当前登录账号的权限，请先用其他管理员账号操作');
        redirect('/users');
    }
    // 至少保留一个启用状态的管理员
    if ($user['role'] === 'admin' && ($role !== 'admin' || $status !== 'enabled')) {
        $others = (int)(fetch_one(
            "SELECT COUNT(*) AS n FROM " . t('users') . " WHERE role = 'admin' AND status = 'enabled' AND id <> ?",
            [$id]
        )['n'] ?? 0);
        if ($others === 0) {
            flash('error', '必须保留至少一个启用状态的管理员');
            redirect('/users');
        }
    }

    execute('UPDATE ' . t('users') . ' SET role = ?, status = ? WHERE id = ?', [$role, $status, $id]);
    log_action('调整用户角色', $user['username'] . ' → ' . authz_roles()[$role] . ' / ' . $status, 'warning');
    flash('success', '已更新 ' . $user['username'] . ' 的角色与状态');
    redirect('/users');
}

function users_password(): void
{
    $id = (int)($_POST['id'] ?? 0);
    $password = (string)($_POST['password'] ?? '');
    $user = fetch_one('SELECT * FROM ' . t('users') . ' WHERE id = ?', [$id]);
    if (!$user) {
        flash('error', '用户不存在');
        redirect('/users');
    }
    if (strlen($password) < 8) {
        flash('error', '密码至少 8 位');
        redirect('/users');
    }
    execute('UPDATE ' . t('users') . ' SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
    log_action('重置用户密码', (string)$user['username'], 'danger');
    flash('success', '已重置 ' . $user['username'] . ' 的密码');
    redirect('/users');
}

function users_delete(): void
{
    $id = (int)($_POST['id'] ?? 0);
    $me = (int)(current_user()['id'] ?? 0);
    $user = fetch_one('SELECT * FROM ' . t('users') . ' WHERE id = ?', [$id]);
    if (!$user) {
        flash('error', '用户不存在');
        redirect('/users');
    }
    if ($id === $me) {
        flash('error', '不能删除当前登录账号');
        redirect('/users');
    }
    if ($user['role'] === 'admin') {
        $others = (int)(fetch_one(
            "SELECT COUNT(*) AS n FROM " . t('users') . " WHERE role = 'admin' AND id <> ?",
            [$id]
        )['n'] ?? 0);
        if ($others === 0) {
            flash('error', '必须保留至少一个管理员');
            redirect('/users');
        }
    }
    execute('DELETE FROM ' . t('users') . ' WHERE id = ?', [$id]);
    log_action('删除用户', (string)$user['username'], 'danger');
    flash('success', '用户 ' . $user['username'] . ' 已删除');
    redirect('/users');
}
