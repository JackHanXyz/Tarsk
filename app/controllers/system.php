<?php
declare(strict_types=1);

/**
 * 系统工具：时区、磁盘、swap、SSH、优化。
 */

function system_page(): void
{
    render('system', [
        'title' => '系统工具',
        'active' => 'system',
        'timezone' => sys_timezone_get(),
        'tzOptions' => array_keys(sys_timezone_windows_map()),
        'disks' => sys_disks(),
        'swap' => sys_swap_status(),
        'ssh' => sys_ssh_status(),
        'optimizes' => sys_optimize_actions(),
    ], 'app');
}

function system_timezone(): void
{
    authz_enforce('POST', '/system');
    $result = sys_timezone_set((string)($_POST['tz'] ?? ''));
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/system');
}

function system_optimize(): void
{
    authz_enforce('POST', '/system');
    $result = sys_optimize_apply((string)($_POST['action'] ?? ''));
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/system');
}

function system_swap(): void
{
    authz_enforce('POST', '/system');
    $result = sys_swap_set_swappiness((int)($_POST['value'] ?? 10));
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/system');
}
