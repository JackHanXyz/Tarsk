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
        // 允许在已存在的目录内新建文件
        $full = files_resolve($rel, false);
        if ($full === null || !is_dir(dirname($full))) {
            flash('error', '文件路径不存在或不在站点目录内');
            redirect('/files');
        }
    }

    if (@file_put_contents($full, $content) === false) {
        flash('error', '写入失败，请检查文件权限');
    } else {
        log_action('保存文件', $rel);
        flash('success', '已保存 ' . basename($full));
    }
    redirect('/files?path=' . urlencode(dirname($rel)));
}

function files_create(): void
{
    $dir = (string)($_POST['path'] ?? '');
    $name = trim((string)($_POST['name'] ?? ''));
    $parent = files_resolve($dir);
    if ($parent === null || !is_dir($parent)) {
        flash('error', '目录不存在');
        redirect('/files');
    }
    if (!preg_match('/^[^\\\\\\/:*?"<>|]{1,120}$/', $name)) {
        flash('error', '文件名称包含非法字符');
        redirect('/files?path=' . urlencode($dir));
    }
    $target = $parent . '/' . $name;
    if (file_exists($target)) {
        flash('error', '同名文件或目录已存在');
    } elseif (@file_put_contents($target, '') === false) {
        flash('error', '创建失败，请检查权限');
    } else {
        log_action('新建文件', $name);
        flash('success', '文件 ' . $name . ' 已创建');
    }
    redirect('/files?path=' . urlencode($dir));
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

    if (files_trash_or_delete($full)) {
        flash('success', '已删除 ' . basename($full));
    } else {
        flash('error', '删除失败，请检查权限');
    }
    redirect('/files?path=' . urlencode(files_dir_of($rel)));
}

function files_batchDelete(): void
{
    $paths = $_POST['paths'] ?? [];
    if (!is_array($paths)) {
        $paths = [];
    }
    $rels = [];
    foreach (array_slice($paths, 0, 50) as $path) {
        $rel = trim(str_replace('\\', '/', (string)$path), '/');
        if ($rel !== '' && !in_array($rel, $rels, true)) {
            $rels[] = $rel;
        }
    }
    if (!$rels) {
        flash('error', '请先勾选要删除的文件或目录');
        redirect('/files');
    }

    $okCount = 0;
    $failures = [];
    foreach ($rels as $rel) {
        $full = files_resolve($rel);
        if ($full === null || $full === files_root() || !file_exists($full)) {
            $failures[] = $rel;
            continue;
        }
        if (files_trash_or_delete($full)) {
            $okCount++;
        } else {
            $failures[] = $rel;
        }
    }

    if ($okCount > 0) {
        flash('success', '已删除 ' . $okCount . ' 项（启用的回收站可在回收站还原）');
    }
    if ($failures) {
        flash('error', '失败 ' . count($failures) . ' 项：' . implode('、', array_slice($failures, 0, 5)));
    }
    redirect('/files?path=' . urlencode(files_dir_of($rels[0])));
}

function files_compress(): void
{
    $paths = $_POST['paths'] ?? [];
    if (!is_array($paths)) {
        $paths = [];
    }
    $single = trim(str_replace('\\', '/', (string)($_POST['path'] ?? '')));
    if ($single !== '') {
        $paths[] = $single;
    }

    $rels = [];
    foreach ($paths as $path) {
        $rel = trim(str_replace('\\', '/', (string)$path), '/');
        if ($rel !== '' && !in_array($rel, $rels, true)) {
            $rels[] = $rel;
        }
    }
    if (count($rels) > 20) {
        flash('error', '一次最多压缩 20 个文件或目录');
        redirect('/files');
    }
    if (!$rels) {
        flash('error', '请先勾选要压缩的文件或目录');
        redirect('/files');
    }

    $root = files_root();
    $targets = [];
    foreach ($rels as $rel) {
        $full = files_resolve($rel);
        if ($full === null || !file_exists($full)) {
            flash('error', '路径无效或不存在：' . $rel);
            redirect('/files');
        }
        if ($rel === 'backup') {
            flash('error', '不能把 backup 备份目录本身打包到 backup 中');
            redirect('/files');
        }
        $targets[$rel] = $full;
    }

    $backupDir = $root . '/backup';
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0777, true);
    }
    $realBackup = str_replace('\\', '/', (string)realpath($backupDir));
    if (!is_dir($backupDir) || $realBackup === '' || ($realBackup !== $root && !str_starts_with($realBackup, $root . '/'))) {
        flash('error', '备份目录不可用，请检查站点目录权限');
        redirect('/files');
    }

    $file = $backupDir . '/' . date('YmdHis') . '.zip';
    $count = 0;

    if (backup_zip_available()) {
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            flash('error', '无法创建压缩文件：' . basename($file));
            redirect('/files');
        }
        try {
            foreach ($targets as $rel => $full) {
                if (is_dir($full)) {
                    $zip->addEmptyDir($rel);
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::LEAVES_ONLY
                    );
                    foreach ($iterator as $item) {
                        if (!$item->isFile()) {
                            continue;
                        }
                        $entry = ltrim(substr(str_replace('\\', '/', $item->getPathname()), strlen($root)), '/');
                        if ($zip->addFile($item->getPathname(), $entry)) {
                            $count++;
                        }
                    }
                } elseif ($zip->addFile($full, $rel)) {
                    $count++;
                }
            }
        } catch (Throwable $e) {
            $zip->close();
            @unlink($file);
            flash('error', '打包失败：' . $e->getMessage());
            redirect('/files');
        }
        $zip->close();
    } else {
        $tar = backup_tar_bin();
        if ($tar === '') {
            flash('error', '当前 PHP 未启用 zip 扩展，且系统未找到 tar，无法压缩');
            redirect('/files');
        }
        // bsdtar 以站点根为工作目录，归档条目相对站点根
        $res = run_cmd(
            escapeshellarg($tar) . ' -a -c -f ' . escapeshellarg($file)
            . ' -C ' . escapeshellarg($root)
            . ' ' . implode(' ', array_map('escapeshellarg', array_keys($targets))),
            300
        );
        if ($res['code'] !== 0 || !is_file($file)) {
            @unlink($file);
            flash('error', '打包失败：' . ($res['error'] ?: mb_substr($res['output'], 0, 200)));
            redirect('/files');
        }
        $count = count($targets);
    }

    if (!is_file($file)) {
        flash('error', '打包失败，未生成压缩文件');
        redirect('/files');
    }

    $name = basename($file);
    $size = (int)@filesize($file);
    log_action('压缩文件', implode('、', array_slice($rels, 0, 5)) . ' -> backup/' . $name, 'info');
    flash('success', '已压缩 ' . $count . ' 项到 backup/' . $name . '（' . bytes($size) . '）');
    redirect('/files?path=' . urlencode(files_dir_of($rels[0])));
}

function files_extract(): void
{
    $rel = trim(str_replace('\\', '/', (string)($_POST['path'] ?? '')), '/');
    $full = files_resolve($rel);
    if ($full === null || !is_file($full)) {
        flash('error', '归档文件不存在');
        redirect('/files');
    }

    $lower = strtolower($full);
    $type = '';
    foreach (['.tar.gz', '.tgz', '.zip', '.tar', '.gz'] as $ext) {
        if (str_ends_with($lower, $ext)) {
            $type = $ext;
            break;
        }
    }
    if ($type === '') {
        flash('error', '仅支持 .zip / .tar / .tar.gz / .tgz / .gz 归档');
        redirect('/files?path=' . urlencode(files_dir_of($rel)));
    }

    $dir = dirname($full);
    $written = 0;
    $rejected = 0;

    if ($type === '.gz') {
        // 纯 gzip 单文件（bsdtar 无法识别非 tar 的 gz），使用 PHP zlib 解压
        if (!function_exists('gzopen')) {
            flash('error', '当前 PHP 未启用 zlib 扩展，无法解压 .gz 文件');
            redirect('/files?path=' . urlencode(files_dir_of($rel)));
        }
        $outName = substr(basename($full), 0, -3);
        if ($outName === '' || !preg_match('/^[^\\\\\\/:*?"<>|]{1,160}$/', $outName)) {
            flash('error', '无法确定解压后的文件名');
            redirect('/files?path=' . urlencode(files_dir_of($rel)));
        }
        $in = @gzopen($full, 'rb');
        if ($in === false) {
            flash('error', '无法打开 gzip 文件');
            redirect('/files?path=' . urlencode(files_dir_of($rel)));
        }
        $target = $dir . '/' . $outName;
        $out = @fopen($target, 'wb');
        if ($out === false) {
            gzclose($in);
            flash('error', '无法写入解压文件，请检查目录权限');
            redirect('/files?path=' . urlencode(files_dir_of($rel)));
        }
        $ok = true;
        while (!gzeof($in)) {
            $chunk = gzread($in, 1024 * 1024);
            if ($chunk === false) {
                $ok = false;
                break;
            }
            fwrite($out, $chunk);
        }
        gzclose($in);
        fclose($out);
        if (!$ok) {
            @unlink($target);
            flash('error', '解压失败，压缩包可能已损坏');
            redirect('/files?path=' . urlencode(files_dir_of($rel)));
        }
        $written = 1;
    } elseif ($type === '.zip' && backup_zip_available()) {
        $zip = new ZipArchive();
        if ($zip->open($full) !== true) {
            flash('error', '无法打开压缩文件');
            redirect('/files?path=' . urlencode(files_dir_of($rel)));
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = str_replace('\\', '/', (string)$zip->getNameIndex($i));
            if ($entry === '') {
                continue;
            }
            // 拒绝绝对路径与 .. 穿越条目
            if (str_starts_with($entry, '/') || preg_match('#^[A-Za-z]:#', $entry) || preg_match('#(^|/)\.\.(/|$)#', $entry)) {
                $rejected++;
                continue;
            }
            $target = $dir . '/' . $entry;
            if (str_ends_with($entry, '/')) {
                if (!is_dir($target)) {
                    @mkdir($target, 0777, true);
                }
                continue;
            }
            $parentDir = dirname($target);
            if (!is_dir($parentDir)) {
                @mkdir($parentDir, 0777, true);
            }
            $stream = $zip->getStream($entry);
            if ($stream === false) {
                continue;
            }
            $out = @fopen($target, 'wb');
            if ($out !== false) {
                stream_copy_to_stream($stream, $out);
                fclose($out);
                $written++;
            }
            fclose($stream);
        }
        $zip->close();
    } else {
        $tar = backup_tar_bin();
        if ($tar === '') {
            flash('error', '当前 PHP 未启用 zip 扩展，且系统未找到 tar，无法解压');
            redirect('/files?path=' . urlencode(files_dir_of($rel)));
        }

        // 解压前先列出条目，拒绝绝对路径与 ..，并把工作目录限定为归档所在目录
        $list = run_cmd(escapeshellarg($tar) . ' -t -f ' . escapeshellarg($full), 120);
        if ($list['code'] !== 0) {
            flash('error', '无法读取归档内容：' . ($list['error'] ?: mb_substr($list['output'], 0, 200)));
            redirect('/files?path=' . urlencode(files_dir_of($rel)));
        }
        $unsafe = false;
        foreach (preg_split('/\r?\n/', trim($list['output'])) as $entry) {
            $entry = str_replace('\\', '/', trim($entry));
            if ($entry === '') {
                continue;
            }
            if (str_starts_with($entry, '/') || preg_match('#^[A-Za-z]:#', $entry) || preg_match('#(^|/)\.\.(/|$)#', $entry)) {
                $unsafe = true;
                break;
            }
        }
        if ($unsafe) {
            flash('error', '归档包含不安全的路径（.. 或绝对路径），已拒绝解压');
            redirect('/files?path=' . urlencode(files_dir_of($rel)));
        }

        $res = run_cmd(
            escapeshellarg($tar) . ' -x -f ' . escapeshellarg($full) . ' -C ' . escapeshellarg($dir),
            300
        );
        if ($res['code'] !== 0) {
            flash('error', '解压失败：' . ($res['error'] ?: mb_substr($res['output'], 0, 200)));
            redirect('/files?path=' . urlencode(files_dir_of($rel)));
        }
        $written = count(array_filter(preg_split('/\r?\n/', trim($list['output']))));
    }

    $dirText = files_dir_of($rel) === '' ? '站点根目录' : files_dir_of($rel);
    $message = '已解压到 ' . $dirText
        . ($written > 0 ? '（' . $written . ' 个条目）' : '')
        . ($rejected > 0 ? '，已跳过 ' . $rejected . ' 个不安全条目' : '');
    log_action('解压文件', $rel, 'info');
    flash('success', $message);
    redirect('/files?path=' . urlencode(files_dir_of($rel)));
}

function files_chmod(): void
{
    $rel = (string)($_POST['path'] ?? '');
    $mode = trim((string)($_POST['mode'] ?? ''));
    $full = files_resolve($rel);
    if ($full === null || !file_exists($full)) {
        flash('error', '文件或目录不存在');
        redirect('/files');
    }
    if (!preg_match('/^0?[0-7]{3,4}$/', $mode)) {
        flash('error', '权限值需为 3-4 位八进制数字，如 644、755、0755');
        redirect('/files?path=' . urlencode(files_dir_of($rel)));
    }

    $ok = @chmod($full, (int)octdec($mode));
    log_action('修改权限', $rel . ' -> ' . $mode, $ok ? 'info' : 'warning');

    if (PHP_OS_FAMILY === 'Windows') {
        flash('success', '已尝试设置权限 ' . $mode . '；Windows 下权限位可能不生效，仅 POSIX 有效');
    } elseif ($ok) {
        flash('success', '权限已设置为 ' . $mode);
    } else {
        flash('error', '修改权限失败，请检查文件所有者或权限');
    }
    redirect('/files?path=' . urlencode(files_dir_of($rel)));
}

function files_fetch(): void
{
    $url = trim((string)($_POST['url'] ?? ''));
    $dir = trim(str_replace('\\', '/', (string)($_POST['dir'] ?? '')), '/');
    $parent = files_resolve($dir);
    if ($parent === null || !is_dir($parent)) {
        flash('error', '目录不存在');
        redirect('/files');
    }

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])
        || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) {
        flash('error', '仅支持 http / https 链接');
        redirect('/files?path=' . urlencode($dir));
    }

    $name = trim((string)($_POST['filename'] ?? ''));
    if ($name === '') {
        $name = rawurldecode(basename(str_replace('\\', '/', (string)($parts['path'] ?? ''))));
    }
    $name = basename(str_replace('\\', '/', $name));
    if ($name === '' || $name === '.' || $name === '..' || !preg_match('/^[^\\\\\\/:*?"<>|]{1,160}$/', $name)) {
        flash('error', '文件名不合法，请在保存名称中手动填写');
        redirect('/files?path=' . urlencode($dir));
    }

    $target = $parent . '/' . $name;
    if (file_exists($target)) {
        flash('error', '同名文件已存在：' . $name);
        redirect('/files?path=' . urlencode($dir));
    }

    $max = 500 * 1024 * 1024;
    $tmp = storage_path('tmp/fetch_' . bin2hex(random_bytes(6)) . '.part');
    $size = 0;

    if (function_exists('curl_init')) {
        $fp = @fopen($tmp, 'wb');
        if ($fp === false) {
            flash('error', '无法创建临时文件');
            redirect('/files?path=' . urlencode($dir));
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_USERAGENT => 'TarskPanel/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FAILONERROR => true,
        ];
        if (defined('CURLOPT_PROTOCOLS')) {
            $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS')) {
            $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_MAXFILESIZE')) {
            $opts[CURLOPT_MAXFILESIZE] = $max;
        }
        curl_setopt_array($ch, $opts);
        $ok = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $code < 200 || $code >= 300) {
            @unlink($tmp);
            flash('error', '下载失败：' . ($error !== '' ? $error : 'HTTP ' . $code));
            redirect('/files?path=' . urlencode($dir));
        }
        $size = (int)@filesize($tmp);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 600,
                'follow_location' => 1,
                'max_redirects' => 5,
                'user_agent' => 'TarskPanel/1.0',
            ],
        ]);
        $in = @fopen($url, 'rb', false, $context);
        $out = $in !== false ? @fopen($tmp, 'wb') : false;
        if ($in === false || $out === false) {
            if ($in !== false) {
                fclose($in);
            }
            @unlink($tmp);
            flash('error', '下载失败，请检查链接或网络');
            redirect('/files?path=' . urlencode($dir));
        }
        while (!feof($in)) {
            $chunk = fread($in, 1024 * 1024);
            if ($chunk === false) {
                break;
            }
            $size += strlen($chunk);
            if ($size > $max) {
                break;
            }
            fwrite($out, $chunk);
        }
        fclose($in);
        fclose($out);
    }

    if ($size > $max) {
        @unlink($tmp);
        flash('error', '文件超过 500 MB 限制');
        redirect('/files?path=' . urlencode($dir));
    }
    if ($size <= 0) {
        @unlink($tmp);
        flash('error', '下载内容为空或下载失败');
        redirect('/files?path=' . urlencode($dir));
    }
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        flash('error', '保存失败，请检查目录写入权限');
        redirect('/files?path=' . urlencode($dir));
    }

    log_action('远程下载', $url . ' -> ' . files_rel_path($target), 'info');
    flash('success', '已下载 ' . $name . '（' . bytes($size) . '）');
    redirect('/files?path=' . urlencode($dir));
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

/**
 * 相对站点根的目录（用于操作完成后的跳转）
 */
function files_dir_of(string $rel): string
{
    $dir = str_replace('\\', '/', dirname(str_replace('\\', '/', $rel)));
    return ($dir === '.' || $dir === '') ? '' : trim($dir, '/');
}

/**
 * 绝对路径转站点相对路径（用于日志）
 */
function files_rel_path(string $full): string
{
    $root = files_root();
    $full = str_replace('\\', '/', $full);
    if ($full === $root) {
        return '';
    }
    return ltrim(str_starts_with($full, $root . '/') ? substr($full, strlen($root)) : $full, '/');
}

/**
 * 删除文件或目录：回收站开启时优先移入回收站，失败或未开启时直接删除
 */
function files_trash_or_delete(string $full): bool
{
    $rel = files_rel_path($full);

    // 回收站开启时：先移入回收站而非直接删除
    if ((string)setting('recycle_enabled', '1') === '1') {
        $trashDir = storage_path('trash');
        if (!is_dir($trashDir)) {
            @mkdir($trashDir, 0777, true);
        }
        $trashPath = $trashDir . '/' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '_' . basename($full);
        $isDir = is_dir($full);
        $size = $isDir ? dir_size($full) : (int)@filesize($full);

        $record = function (string $trashPath) use ($full, $isDir, $size, $rel): bool {
            execute(
                'INSERT INTO ' . t('recycle') . ' (name, orig_path, trash_path, is_dir, size, deleted_at)
                 VALUES (?,?,?,?,?,?)',
                [basename($full), $full, str_replace('\\', '/', $trashPath), $isDir ? 1 : 0, $size, now()]
            );
            log_action('移入回收站', $rel, 'warning');
            return true;
        };

        if (@rename($full, $trashPath)) {
            return $record($trashPath);
        }

        // 跨盘 rename 会失败（Windows 上目录尤为常见）：改为复制到回收站成功后再删除源，
        // 避免"看起来进了回收站、实际已被永久删除"
        $copied = $isDir ? files_copy_path($full, $trashPath) : (@copy($full, $trashPath) && is_file($trashPath));
        if ($copied) {
            $removed = $isDir ? delete_path($full) : @unlink($full);
            if ($removed) {
                return $record($trashPath);
            }
            // 源没删掉则撤销副本，避免出现两份
            $isDir ? delete_path($trashPath) : @unlink($trashPath);
        }
        // 复制与删除都失败时退回直接删除
    }

    if (delete_path($full)) {
        log_action('删除文件', $rel, 'danger');
        return true;
    }
    return false;
}

/**
 * 递归复制目录（跨盘移入回收站时使用）
 */
function files_copy_path(string $src, string $dst): bool
{
    if (is_file($src)) {
        return @copy($src, $dst);
    }
    if (!is_dir($src)) {
        return false;
    }
    if (!is_dir($dst) && !@mkdir($dst, 0777, true)) {
        return false;
    }
    $items = @scandir($src) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $ok = files_copy_path($src . '/' . $item, $dst . '/' . $item);
        if (!$ok) {
            return false;
        }
    }
    return true;
}
