<?php
declare(strict_types=1);

/**
 * 回收站：文件删除后先移入回收站，可还原或彻底删除
 */

function recycle_page(): void
{
    $items = fetch_all('SELECT * FROM ' . t('recycle') . ' ORDER BY id DESC LIMIT 500');
    $totalSize = 0;
    foreach ($items as $item) {
        $totalSize += (int)$item['size'];
    }
    render('recycle', [
        'title' => '回收站',
        'active' => 'recycle',
        'items' => $items,
        'totalSize' => $totalSize,
        'enabled' => (string)setting('recycle_enabled', '1') === '1',
    ], 'app');
}

function recycle_restore(): void
{
    $id = (int)($_POST['id'] ?? 0);
    $row = fetch_one('SELECT * FROM ' . t('recycle') . ' WHERE id = ?', [$id]);
    if (!$row) {
        flash('error', '记录不存在');
        redirect('/recycle');
    }

    $trashPath = str_replace('\\', '/', (string)$row['trash_path']);
    $origPath = str_replace('\\', '/', (string)$row['orig_path']);

    if (!file_exists($trashPath)) {
        execute('DELETE FROM ' . t('recycle') . ' WHERE id = ?', [$id]);
        flash('error', '回收站中的文件已丢失，记录已清理');
        redirect('/recycle');
    }

    // 还原目标必须位于站点根目录内
    $root = sites_root();
    $realRoot = str_replace('\\', '/', realpath($root) ?: $root);
    if ($origPath !== $realRoot && !str_starts_with($origPath, $realRoot . '/')) {
        flash('error', '原始路径不在站点目录内，禁止还原');
        redirect('/recycle');
    }
    if (file_exists($origPath)) {
        flash('error', '原始位置已存在同名文件或目录，无法还原');
        redirect('/recycle');
    }

    $parent = dirname($origPath);
    if (!is_dir($parent)) {
        @mkdir($parent, 0777, true);
    }

    if (@rename($trashPath, $origPath)) {
        execute('DELETE FROM ' . t('recycle') . ' WHERE id = ?', [$id]);
        log_action('还原文件', $row['name']);
        flash('success', '已还原 ' . $row['name']);
    } else {
        flash('error', '还原失败，请检查权限');
    }
    redirect('/recycle');
}

function recycle_delete(): void
{
    $id = (int)($_POST['id'] ?? 0);
    $row = fetch_one('SELECT * FROM ' . t('recycle') . ' WHERE id = ?', [$id]);
    if (!$row) {
        flash('error', '记录不存在');
        redirect('/recycle');
    }
    $trashPath = str_replace('\\', '/', (string)$row['trash_path']);
    if (file_exists($trashPath) && str_starts_with($trashPath, str_replace('\\', '/', storage_path('trash')) . '/')) {
        delete_path($trashPath);
    }
    execute('DELETE FROM ' . t('recycle') . ' WHERE id = ?', [$id]);
    log_action('彻底删除', $row['name'], 'danger');
    flash('success', '已彻底删除 ' . $row['name']);
    redirect('/recycle');
}

function recycle_clear(): void
{
    $trashRoot = str_replace('\\', '/', storage_path('trash'));
    $items = fetch_all('SELECT id, trash_path FROM ' . t('recycle'));
    $count = 0;
    foreach ($items as $item) {
        $path = str_replace('\\', '/', (string)$item['trash_path']);
        if (file_exists($path) && str_starts_with($path, $trashRoot . '/')) {
            delete_path($path);
            $count++;
        }
        execute('DELETE FROM ' . t('recycle') . ' WHERE id = ?', [(int)$item['id']]);
    }
    log_action('清空回收站', $count . ' 项', 'danger');
    flash('success', '回收站已清空（' . $count . ' 项）');
    redirect('/recycle');
}
