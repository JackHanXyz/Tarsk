<?php
declare(strict_types=1);

/**
 * 文件管理（真实读写，根目录为站点目录）
 */
function files_page(): void
{
    $rel = (string)($_GET['path'] ?? '');
    $current = files_resolve($rel);
    if ($current === null) {
        flash('error', '路径无效或不存在');
        redirect('/files');
    }
    if (!is_dir($current)) {
        flash('error', '该路径不是目录');
        redirect('/files');
    }

    $entries = [];
    $items = @scandir($current) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $full = $current . '/' . $item;
        $isDir = is_dir($full);
        $entries[] = [
            'name' => $item,
            'is_dir' => $isDir,
            'size' => $isDir ? dir_size($full) : (int)@filesize($full),
            'mtime' => @filemtime($full) ?: 0,
            'perm' => file_permission_text($full),
            'ext' => $isDir ? '' : strtolower(pathinfo($item, PATHINFO_EXTENSION)),
        ];
    }
    usort($entries, function ($a, $b) {
        if ($a['is_dir'] !== $b['is_dir']) {
            return $a['is_dir'] ? -1 : 1;
        }
        return strcasecmp($a['name'], $b['name']);
    });

    $root = files_root();
    $rel = trim(str_replace('\\', '/', $current === $root ? '' : substr($current, strlen($root))), '/');
    $crumbs = [];
    if ($rel !== '') {
        $acc = '';
        foreach (explode('/', $rel) as $part) {
            $acc = $acc === '' ? $part : $acc . '/' . $part;
            $crumbs[] = ['name' => $part, 'path' => $acc];
        }
    }

    $total = 0;
    foreach ($entries as $entry) {
        $total += $entry['size'];
    }

    render('files', [
        'title' => '文件',
        'active' => 'files',
        'entries' => $entries,
        'rel' => $rel,
        'root' => $root,
        'crumbs' => $crumbs,
        'total' => $total,
        'editable' => true,
    ], 'app');
}

function files_read(): void
{
    $rel = (string)($_GET['path'] ?? '');
    $full = files_resolve($rel);
    if ($full === null || !is_file($full)) {
        json_response(['ok' => false, 'message' => '文件不存在'], 404);
    }
    if (filesize($full) > 4 * 1024 * 1024) {
        json_response(['ok' => false, 'message' => '文件超过 4 MB，暂不支持在线编辑'], 400);
    }
    $content = (string)@file_get_contents($full);
    if (preg_match('/\x00/', $content)) {
        json_response(['ok' => false, 'message' => '二进制文件不支持编辑'], 400);
    }
    json_response(['ok' => true, 'name' => basename($full), 'content' => $content, 'path' => $rel]);
}

function files_save(): void
{
    $rel = (string)($_POST['path'] ?? '');
    $content = (string)($_POST['content'] ?? '');
    $full = files_resolve($rel);
    if ($full === null || !is_file($full)) {
        flash('error', '文件不存在');
        redirect('/files?path=' . urlencode(dirname($rel)));
    }
    if (@file_put_contents($full, $content) === false) {
        flash('error', '写入失败，请检查文件权限');
    } else {
        log_action('保存文件', $rel);
        flash('success', '已保存 ' . basename($full));
    }
    redirect('/files?path=' . urlencode(dirname($rel)));
}

function files_mkdir(): void
{
    $dir = (string)($_POST['path'] ?? '');
    $name = trim((string)($_POST['name'] ?? ''));
    $parent = files_resolve($dir);
    if ($parent === null || !is_dir($parent)) {
        flash('error', '目录不存在');
        redirect('/files');
    }
    if (!preg_match('/^[^\\\\\\/:*?"<>|]{1,120}$/', $name)) {
        flash('error', '名称包含非法字符');
        redirect('/files?path=' . urlencode($dir));
    }
    $target = $parent . '/' . $name;
    if (file_exists($target)) {
        flash('error', '同名文件或目录已存在');
    } elseif (@mkdir($target, 0777)) {
        log_action('新建目录', $name);
        flash('success', '目录 ' . $name . ' 已创建');
    } else {
        flash('error', '创建失败，请检查权限');
    }
    redirect('/files?path=' . urlencode($dir));
}

function files_rename(): void
{
    $rel = (string)($_POST['path'] ?? '');
    $newName = trim((string)($_POST['name'] ?? ''));
    $full = files_resolve($rel);
    if ($full === null || !file_exists($full)) {
        flash('error', '文件不存在');
        redirect('/files');
    }
    if (!preg_match('/^[^\\\\\\/:*?"<>|]{1,120}$/', $newName)) {
        flash('error', '名称包含非法字符');
        redirect('/files?path=' . urlencode(dirname($rel)));
    }
    $target = dirname($full) . '/' . $newName;
    if (file_exists($target)) {
        flash('error', '目标名称已存在');
    } elseif (@rename($full, $target)) {
        log_action('重命名', $rel . ' -> ' . $newName);
        flash('success', '已重命名为 ' . $newName);
    } else {
        flash('error', '重命名失败');
    }
    redirect('/files?path=' . urlencode(dirname($rel)));
}

function files_delete(): void
{
    $rel = (string)($_POST['path'] ?? '');
    $full = files_resolve($rel);
    if ($full === null || $full === files_root()) {
        flash('error', '不能删除根目录');
        redirect('/files');
    }
    if (!file_exists($full)) {
        flash('error', '文件不存在');
        redirect('/files');
    }
    if (delete_path($full)) {
        log_action('删除文件', $rel, 'danger');
        flash('success', '已删除 ' . basename($full));
    } else {
        flash('error', '删除失败，请检查权限');
    }
    redirect('/files?path=' . urlencode(dirname($rel)));
}

function files_upload(): void
{
    $dir = (string)($_POST['path'] ?? '');
    $parent = files_resolve($dir);
    if ($parent === null || !is_dir($parent)) {
        flash('error', '目录不存在');
        redirect('/files');
    }
    if (empty($_FILES['file']) || !is_array($_FILES['file']['name'])) {
        flash('error', '没有收到文件');
        redirect('/files?path=' . urlencode($dir));
    }

    $okCount = 0;
    $failures = [];
    foreach ($_FILES['file']['name'] as $index => $name) {
        if (($_FILES['file']['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $failures[] = $name . '（上传错误）';
            continue;
        }
        $safe = basename(str_replace('\\', '/', (string)$name));
        if (!preg_match('/^[^\\\\\\/:*?"<>|]{1,160}$/', $safe)) {
            $failures[] = (string)$name . '（名称非法）';
            continue;
        }
        $target = $parent . '/' . $safe;
        if (move_uploaded_file($_FILES['file']['tmp_name'][$index], $target)) {
            $okCount++;
        } else {
            $failures[] = (string)$name;
        }
    }

    if ($okCount > 0) {
        log_action('上传文件', $okCount . ' 个文件至 ' . $dir);
        flash('success', '成功上传 ' . $okCount . ' 个文件');
    }
    if ($failures) {
        flash('error', '失败：' . implode('、', array_slice($failures, 0, 5)));
    }
    redirect('/files?path=' . urlencode($dir));
}

function files_download(): void
{
    $rel = (string)($_GET['path'] ?? '');
    $full = files_resolve($rel);
    if ($full === null || !is_file($full)) {
        http_response_code(404);
        exit('文件不存在');
    }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($full));
    header('Content-Disposition: attachment; filename="' . rawurlencode(basename($full)) . '"');
    readfile($full);
    exit;
}

/* ------------------------------- 工具方法 ------------------------------- */

function files_root(): string
{
    $root = sites_root();
    if (!is_dir($root)) {
        @mkdir($root, 0777, true);
    }
    $real = realpath($root);
    return str_replace('\\', '/', $real ?: $root);
}

function files_resolve(?string $rel, bool $mustExist = true): ?string
{
    $root = files_root();
    $rel = str_replace('\\', '/', (string)$rel);
    $rel = trim($rel, '/');
    if ($rel === '.' || strpos($rel, '..') !== false) {
        return null;
    }
    $full = $root . ($rel === '' ? '' : '/' . $rel);

    if ($mustExist) {
        $real = realpath($full);
        if ($real === false) {
            return null;
        }
        $real = str_replace('\\', '/', $real);
        if ($real !== $root && !str_starts_with($real, $root . '/')) {
            return null;
        }
        return $real;
    }

    $parent = realpath(dirname($full));
    if ($parent === false) {
        return null;
    }
    $parent = str_replace('\\', '/', $parent);
    if ($parent !== $root && !str_starts_with($parent, $root . '/')) {
        return null;
    }
    return $full;
}
