<?php
declare(strict_types=1);

/**
 * 异地备份：目标管理、连通性测试、把本地备份推到远端
 */

function backups_remote_page(): void
{
    render('backups_remote', [
        'title' => '异地备份',
        'active' => 'backups',
        'targets' => remote_targets(),
        'kinds' => remote_kind_labels(),
        'backups' => backup_list(),
    ], 'app');
}

function backups_remote_save(): void
{
    $id = (int)($_POST['id'] ?? 0);
    $result = remote_target_save($_POST, $id);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/backups/remote');
}

function backups_remote_delete(): void
{
    $result = remote_target_delete((int)($_POST['id'] ?? 0));
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/backups/remote');
}

function backups_remote_test(): void
{
    $target = remote_target((int)($_POST['id'] ?? 0));
    if (!$target) {
        flash('error', '目标不存在');
        redirect('/backups/remote');
    }
    @set_time_limit(60);
    $result = remote_test($target);
    execute(
        'UPDATE ' . t('remote_targets') . ' SET last_result = ?, last_at = ? WHERE id = ?',
        [mb_substr(($result['ok'] ? '成功：' : '失败：') . $result['message'], 0, 255), now(), (int)$target['id']]
    );
    flash($result['ok'] ? 'success' : 'error', $target['name'] . '：' . $result['message']);
    redirect('/backups/remote');
}

function backups_remote_upload(): void
{
    $target = remote_target((int)($_POST['id'] ?? 0));
    $file = (string)($_POST['file'] ?? '');
    if (!$target) {
        flash('error', '目标不存在');
        redirect('/backups/remote');
    }
    $path = backup_resolve($file);
    if ($path === null) {
        flash('error', '备份文件不存在');
        redirect('/backups/remote');
    }
    @set_time_limit(600);
    $result = remote_upload($target, $path);
    flash($result['ok'] ? 'success' : 'error', $target['name'] . '：' . $result['message'] . '（' . basename($path) . '）');
    redirect('/backups/remote');
}
