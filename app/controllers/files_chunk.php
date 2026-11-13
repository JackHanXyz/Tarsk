<?php
declare(strict_types=1);

/**
 * 大文件分片上传：init → chunk(×N) → finish
 * 支持断点续传：init 会返回已上传的分片序号，前端跳过这些分片。
 */

require_once __DIR__ . '/files.php';

function chunk_root(): string
{
    $dir = storage_path('tmp/chunks');
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return str_replace('\\', '/', $dir);
}

function chunk_valid_id(string $id): bool
{
    return (bool)preg_match('/^[a-f0-9]{32}$/', $id);
}

function chunk_dir(string $id): string
{
    return chunk_root() . '/' . $id;
}

function chunk_prune(int $hours = 12): void
{
    $threshold = time() - $hours * 3600;
    foreach (glob(chunk_root() . '/*') ?: [] as $dir) {
        if (is_dir($dir) && (int)@filemtime($dir) < $threshold) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }
}

function chunk_uploaded(string $id): array
{
    $dir = chunk_dir($id);
    if (!is_dir($dir)) {
        return [];
    }
    $indexes = [];
    foreach (glob($dir . '/*.part') ?: [] as $file) {
        $name = basename($file, '.part');
        if (ctype_digit($name)) {
            $indexes[] = (int)$name;
        }
    }
    sort($indexes);
    return $indexes;
}

/**
 * 初始化上传会话（幂等：同一 upload_id 重复调用返回已上传分片）
 */
function files_chunk_init(): void
{
    $id = (string)($_POST['upload_id'] ?? '');
    $dir = (string)($_POST['path'] ?? '');
    $name = (string)($_POST['name'] ?? '');
    $size = (int)($_POST['size'] ?? 0);
    $chunkSize = (int)($_POST['chunk_size'] ?? 0);

    if (!chunk_valid_id($id)) {
        json_response(['ok' => false, 'message' => 'upload_id 不合法'], 400);
    }
    $parent = files_resolve($dir);
    if ($parent === null || !is_dir($parent)) {
        json_response(['ok' => false, 'message' => '目标目录不存在'], 400);
    }
    if (!preg_match('/^[^\\\\\\/:*?"<>|]{1,160}$/', $name)) {
        json_response(['ok' => false, 'message' => '文件名不合法'], 400);
    }
    if ($size <= 0) {
        json_response(['ok' => false, 'message' => '文件大小不合法'], 400);
    }
    if ($chunkSize <= 0 || $chunkSize > 64 * 1024 * 1024) {
        $chunkSize = 2 * 1024 * 1024;
    }

    chunk_prune();
    $target = chunk_dir($id);
    if (!is_dir($target)) {
        @mkdir($target, 0777, true);
    }
    @file_put_contents($target . '/meta.json', json_encode([
        'name' => $name,
        'dir' => $parent,
        'size' => $size,
        'chunk_size' => $chunkSize,
        'created_at' => now(),
    ], JSON_UNESCAPED_UNICODE));

    json_response([
        'ok' => true,
        'upload_id' => $id,
        'uploaded' => chunk_uploaded($id),
        'chunk_size' => $chunkSize,
        'total_chunks' => (int)ceil($size / $chunkSize),
    ]);
}

/**
 * 接收单个分片
 */
function files_chunk_put(): void
{
    $id = (string)($_POST['upload_id'] ?? '');
    $index = (int)($_POST['index'] ?? -1);
    if (!chunk_valid_id($id) || $index < 0) {
        json_response(['ok' => false, 'message' => '参数不合法'], 400);
    }
    $dir = chunk_dir($id);
    if (!is_dir($dir) || !is_file($dir . '/meta.json')) {
        json_response(['ok' => false, 'message' => '上传会话不存在，请重新开始'], 409);
    }
    if (!isset($_FILES['chunk']) || ($_FILES['chunk']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_response(['ok' => false, 'message' => '分片未收到或上传出错'], 400);
    }
    $tmp = (string)$_FILES['chunk']['tmp_name'];
    $dest = $dir . '/' . $index . '.part';
    if (!@move_uploaded_file($tmp, $dest)) {
        json_response(['ok' => false, 'message' => '分片写入失败'], 500);
    }
    @filemtime($dir);
    json_response(['ok' => true, 'index' => $index, 'uploaded' => chunk_uploaded($id)]);
}

/**
 * 合并分片并落盘
 */
function files_chunk_finish(): void
{
    $id = (string)($_POST['upload_id'] ?? '');
    if (!chunk_valid_id($id)) {
        json_response(['ok' => false, 'message' => 'upload_id 不合法'], 400);
    }
    $dir = chunk_dir($id);
    $metaFile = $dir . '/meta.json';
    if (!is_file($metaFile)) {
        json_response(['ok' => false, 'message' => '上传会话不存在'], 409);
    }
    $meta = json_decode((string)file_get_contents($metaFile), true);
    if (!is_array($meta)) {
        json_response(['ok' => false, 'message' => '会话元数据损坏'], 500);
    }
    $size = (int)($meta['size'] ?? 0);
    $chunkSize = (int)($meta['chunk_size'] ?? 1);
    $total = (int)ceil($size / max(1, $chunkSize));
    $uploaded = chunk_uploaded($id);
    if (count($uploaded) < $total) {
        $missing = array_values(array_diff(range(0, $total - 1), $uploaded));
        json_response(['ok' => false, 'message' => '还有 ' . count($missing) . ' 个分片未上传', 'missing' => $missing], 409);
    }

    // meta 里存的是初始化时解析好的绝对目录，这里只做一次归属校验
    $parent = str_replace('\\', '/', (string)($meta['dir'] ?? ''));
    $rootReal = files_root();
    $parentReal = $parent !== '' ? str_replace('\\', '/', (string)realpath($parent)) : '';
    if ($parentReal === '' || !is_dir($parentReal)
        || ($parentReal !== $rootReal && !str_starts_with($parentReal, $rootReal . '/'))) {
        json_response(['ok' => false, 'message' => '目标路径已失效'], 400);
    }
    $parent = $parentReal;
    if (!is_dir($parent)) {
        @mkdir($parent, 0777, true);
    }
    $name = (string)($meta['name'] ?? '');
    if (!preg_match('/^[^\\\\\\/:*?"<>|]{1,160}$/', $name)) {
        json_response(['ok' => false, 'message' => '文件名不合法'], 400);
    }
    $target = $parent . '/' . $name;
    $temp = $parent . '/.tarsk_upload_' . $id . '.tmp';

    $out = @fopen($temp, 'wb');
    if (!$out) {
        json_response(['ok' => false, 'message' => '无法创建目标文件（目录不可写？）'], 500);
    }
    for ($i = 0; $i < $total; $i++) {
        $part = $dir . '/' . $i . '.part';
        if (!is_file($part)) {
            fclose($out);
            @unlink($temp);
            json_response(['ok' => false, 'message' => '分片 ' . $i . ' 丢失'], 500);
        }
        $in = @fopen($part, 'rb');
        if (!$in) {
            fclose($out);
            @unlink($temp);
            json_response(['ok' => false, 'message' => '读取分片 ' . $i . ' 失败'], 500);
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
    }
    fclose($out);

    $finalSize = (int)@filesize($temp);
    if ($finalSize !== $size) {
        @unlink($temp);
        json_response(['ok' => false, 'message' => '合并后大小不一致（' . $finalSize . ' ≠ ' . $size . '）'], 500);
    }
    if (!@rename($temp, $target)) {
        @unlink($temp);
        json_response(['ok' => false, 'message' => '落盘失败，请检查目录权限'], 500);
    }

    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);

    $relDir = trim((string)($_POST['path'] ?? ''), '/');
    log_action('分片上传完成', ($relDir !== '' ? $relDir . '/' : '') . $name . '（' . bytes($size) . '）');
    json_response(['ok' => true, 'message' => '上传完成', 'file' => $name, 'size' => $size]);
}

function files_chunk_abort(): void
{
    $id = (string)($_POST['upload_id'] ?? '');
    if (!chunk_valid_id($id)) {
        json_response(['ok' => false, 'message' => 'upload_id 不合法'], 400);
    }
    $dir = chunk_dir($id);
    if (is_dir($dir)) {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
    json_response(['ok' => true, 'message' => '已取消']);
}
