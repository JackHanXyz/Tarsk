<?php
declare(strict_types=1);

/**
 * 在线升级：检查 GitHub 最新版本并一键更新
 */

function update_page(): void
{
    $remote = json_decode((string)setting('update_cache', ''), true);
    $state = updater_state();

    render('update', [
        'title' => '版本更新',
        'active' => 'update',
        'local_version' => updater_local_version(),
        'local_sha' => updater_local_sha(),
        'remote' => is_array($remote) ? $remote : null,
        'state' => $state,
        'branch' => updater_branch(),
        'proxy' => (string)setting('update_proxy', ''),
        'repo' => updater_repo(),
        'last_result' => (string)setting('update_last_result', ''),
        'repo_url' => 'https://github.com/' . updater_repo(),
    ], 'app');
}

function update_check(): void
{
    require_login();
    @set_time_limit(120);
    $result = updater_check(true);
    setting_set('update_last_result', now() . ' 检查：' . $result['message']);
    log_action('检查面板更新', $result['message'], 'info');
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/update');
}

function update_apply(): void
{
    require_login();
    @set_time_limit(600);

    $result = updater_apply(true);
    setting_set('update_last_result', now() . ' 更新：' . $result['message']);
    log_action('在线更新面板', $result['message'], $result['ok'] ? 'warning' : 'danger');
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/update');
}

function update_settings(): void
{
    require_login();
    $branch = trim((string)($_POST['branch'] ?? 'main'));
    $proxy = trim((string)($_POST['proxy'] ?? ''));

    if ($branch !== '' && !preg_match('/^[A-Za-z0-9._\/-]{1,80}$/', $branch)) {
        flash('error', '分支名格式不正确');
        redirect('/update');
    }
    if ($proxy !== '' && !preg_match('#^http(s)?://[A-Za-z0-9.:_\-]+$#', $proxy)) {
        flash('error', '代理地址格式应形如 http://127.0.0.1:10808');
        redirect('/update');
    }
    setting_set('update_branch', $branch !== '' ? $branch : 'main');
    setting_set('update_proxy', $proxy);
    setting_set('update_cache', '');
    log_action('更新通道设置', '分支 ' . $branch . '；代理 ' . ($proxy !== '' ? $proxy : '直连'), 'info');
    flash('success', '更新通道设置已保存');
    redirect('/update');
}
