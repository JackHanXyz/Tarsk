<?php
declare(strict_types=1);

/**
 * 权限模型：admin / operator / viewer 三种角色
 *
 * 判定放在路由分发处（按方法 + 路径前缀），避免每个控制器各写一遍导致漏判。
 * 默认策略：GET 至少需要 view，POST 默认要求 panel.manage（新增接口不会意外对外开放）。
 */

function authz_roles(): array
{
    return [
        'admin' => '管理员',
        'operator' => '运维',
        'viewer' => '只读',
    ];
}

function authz_permission_labels(): array
{
    return [
        'view' => '查看面板',
        'panel.manage' => '面板管理（用户 / 设置 / 更新 / 插件 / API）',
        'system.manage' => '系统操作（终端 / 进程 / 防火墙 / Docker / 环境配置）',
        'site.manage' => '站点与证书（建站 / SSL / FTP / 一键部署）',
        'file.manage' => '文件写入（上传 / 编辑 / 删除 / 回收站）',
        'db.manage' => '数据库写入（建库 / 备份 / 用户 / 导入）',
        'task.manage' => '计划任务与告警清理',
    ];
}

function authz_matrix(): array
{
    return [
        'admin' => ['view', 'panel.manage', 'system.manage', 'site.manage', 'file.manage', 'db.manage', 'task.manage'],
        'operator' => ['view', 'system.manage', 'site.manage', 'file.manage', 'db.manage', 'task.manage'],
        'viewer' => ['view'],
    ];
}

function authz_role_of(?array $user): string
{
    $role = (string)($user['role'] ?? '');
    return array_key_exists($role, authz_roles()) ? $role : 'viewer';
}

function can(string $permission, ?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$user) {
        return false;
    }
    $role = authz_role_of($user);
    $allowed = authz_matrix()[$role] ?? [];
    return in_array($permission, $allowed, true);
}

function authz_deny(?string $permission = null): void
{
    http_response_code(403);
    $role = authz_role_of(current_user());
    $labels = authz_permission_labels();
    $need = $permission !== null ? ($labels[$permission] ?? $permission) : '该操作';
    if (preg_match('#^/(sites|store)/api(?:/|$)|^/store/install-status$#', current_path())) {
        json_response(['ok' => false, 'message' => '当前角色无权限执行此操作', 'permission' => $permission], 403);
    }
    render('403', [
        'title' => '权限不足',
        'active' => '',
        'message' => '当前账号角色为「' . (authz_roles()[$role] ?? $role) . '」，没有执行「' . $need . '」的权限。',
        'role' => $role,
    ], 'app');
    exit;
}

function require_permission(string $permission): void
{
    if (!can($permission)) {
        authz_deny($permission);
    }
}

/**
 * 路由 → 权限映射；按顺序匹配，第一条命中即生效
 * 元素：[方法（* 表示任意）, 路径正则, 权限]
 */
function authz_route_rules(): array
{
    // 规则里的 '*' 表示"任意写方法（非 GET）"：读页面默认只要 view，
    // 只有少数敏感页面即使 GET 也需要更高权限。
    return [
        // 只读也敏感的页面
        ['GET', '#^/terminal#', 'system.manage'],
        ['GET', '#^/processes#', 'system.manage'],
        ['GET', '#^/system#', 'system.manage'],
        ['GET', '#^/store#', 'system.manage'],
        ['GET', '#^/env/#', 'system.manage'],
        ['GET', '#^/settings#', 'panel.manage'],
        ['GET', '#^/users#', 'panel.manage'],
        ['GET', '#^/api#', 'panel.manage'],
        ['GET', '#^/update#', 'panel.manage'],
        ['GET', '#^/plugins#', 'panel.manage'],
        ['GET', '#^/backups/remote#', 'panel.manage'],
        ['GET', '#^/projects#', 'system.manage'],
        ['GET', '#^/logs#', 'system.manage'],
        ['GET', '#^/alerts#', 'system.manage'],
        ['GET', '#^/cron#', 'task.manage'],
        ['GET', '#^/backups#', 'site.manage'],
        // 直接下发文件内容的接口
        ['GET', '#^/files/download#', 'file.manage'],
        ['GET', '#^/backups/download#', 'site.manage'],

        // 写操作
        ['*', '#^/users#', 'panel.manage'],
        ['*', '#^/settings#', 'panel.manage'],
        ['*', '#^/api#', 'panel.manage'],
        ['*', '#^/update#', 'panel.manage'],
        ['*', '#^/plugins#', 'panel.manage'],
        ['*', '#^/backups/remote#', 'panel.manage'],

        ['*', '#^/terminal#', 'system.manage'],
        ['*', '#^/processes#', 'system.manage'],
        ['*', '#^/firewall#', 'system.manage'],
        ['*', '#^/docker#', 'system.manage'],
        ['*', '#^/system#', 'system.manage'],
        ['*', '#^/store#', 'system.manage'],
        ['*', '#^/env/#', 'system.manage'],
        ['*', '#^/alerts#', 'system.manage'],

        ['*', '#^/sites#', 'site.manage'],
        ['*', '#^/ssl#', 'site.manage'],
        ['*', '#^/ftp#', 'site.manage'],
        ['*', '#^/deploy#', 'site.manage'],

        ['*', '#^/files#', 'file.manage'],
        ['*', '#^/recycle#', 'file.manage'],

        ['*', '#^/projects#', 'system.manage'],
        ['*', '#^/databases#', 'db.manage'],
        ['*', '#^/cron#', 'task.manage'],
    ];
}

function authz_permission_for(string $method, string $path): string
{
    $method = strtoupper($method);
    $isRead = $method === 'GET';
    foreach (authz_route_rules() as [$ruleMethod, $pattern, $permission]) {
        $applies = $ruleMethod === '*' ? !$isRead : ($ruleMethod === $method);
        if (!$applies) {
            continue;
        }
        if (preg_match($pattern, $path)) {
            return $permission;
        }
    }
    return $isRead ? 'view' : 'panel.manage';
}

/**
 * 在路由分发处调用：通过则返回，不通过直接 403 并终止
 */
function authz_enforce(string $method, string $path): void
{
    $permission = authz_permission_for(strtoupper($method), $path);
    if (!can($permission)) {
        authz_deny($permission);
    }
}

/**
 * 导航可见性：按权限过滤侧边栏条目
 */
function authz_nav_allowed(string $url, ?array $user = null): bool
{
    $permission = authz_permission_for('GET', $url);
    if ($permission !== 'view' && !can($permission, $user)) {
        return false;
    }
    return (bool)($user ?? current_user());
}
