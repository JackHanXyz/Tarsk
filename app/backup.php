<?php
declare(strict_types=1);

/**
 * 备份中心：站点目录打包、数据库导出、备份列表/下载/删除/恢复、保留策略
 */

function backup_dir(): string
{
    $dir = backup_path();
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

/**
 * 备份文件元数据：区分站点备份与数据库备份，解析目标名
 */
function backup_meta(string $file): ?array
{
    $name = basename($file);
    $encrypted = str_ends_with(strtolower($name), '.enc');
    $base = $encrypted ? substr($name, 0, -4) : $name;
    $kind = 'other';
    $target = '';
    if (preg_match('/^site_(.+?)_(\d{8}_\d{6})\.zip$/', $base, $m)) {
        $kind = 'site';
        $target = $m[1];
    } elseif (preg_match('/^db_(.+?)_(\d{8}_\d{6})\.sql$/', $base, $m)) {
        $kind = 'db';
        $target = $m[1];
    } elseif (str_ends_with($base, '.sql')) {
        $kind = 'db';
        $target = preg_replace('/_\d{8}_\d{6}\.sql$/', '', $base);
    } elseif (str_ends_with($base, '.zip')) {
        $kind = 'site';
    }
    if (!is_file($file)) {
        return null;
    }
    return [
        'file' => $file,
        'name' => $name,
        'kind' => $kind,
        'target' => $target,
        'encrypted' => $encrypted,
        'size' => (int)@filesize($file),
        'mtime' => (int)@filemtime($file),
    ];
}

function backup_list(): array
{
    $items = [];
    $dir = str_replace('\\', '/', backup_dir());
    foreach (@scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if ($ext !== 'zip' && $ext !== 'sql' && $ext !== 'enc') {
            continue;
        }
        $meta = backup_meta($dir . '/' . $entry);
        if ($meta !== null) {
            $items[] = $meta;
        }
    }
    usort($items, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $items;
}

/**
 * 可用的打包工具：优先 PHP zip 扩展，其次系统 bsdtar（Windows 10+ 自带，Linux 为 tar）
 */
function backup_zip_available(): bool
{
    return class_exists('ZipArchive');
}

function backup_tar_bin(): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        $systemTar = 'C:/Windows/System32/tar.exe';
        return is_file($systemTar) ? str_replace('\\', '/', $systemTar) : '';
    }
    return command_exists('tar') !== null ? 'tar' : '';
}

/**
 * 站点备份：打包站点目录为 zip（PHP zip 扩展或系统 bsdtar）
 */
function backup_site_create(int $siteId, string $password = ''): array
{
    $site = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$siteId]);
    if (!$site) {
        return ['ok' => false, 'message' => '站点不存在'];
    }
    $path = str_replace('\\', '/', (string)$site['path']);
    if (!is_dir($path)) {
        return ['ok' => false, 'message' => '站点目录不存在：' . $path];
    }

    $file = backup_dir() . '/site_' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$site['name']) . '_' . date('Ymd_His') . '.zip';

    if (backup_zip_available()) {
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'message' => '无法创建备份文件：' . $file];
        }
        $count = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $item) {
                if (!$item->isFile()) {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($path) + 1));
                if ($zip->addFile($item->getPathname(), $rel)) {
                    $count++;
                }
            }
        } catch (Throwable $e) {
            $zip->close();
            @unlink($file);
            return ['ok' => false, 'message' => '打包失败：' . $e->getMessage()];
        }
        $zip->close();
    } else {
        $tar = backup_tar_bin();
        if ($tar === '') {
            return ['ok' => false, 'message' => '当前 PHP 未启用 zip 扩展，且系统未找到 tar，无法打包站点备份'];
        }
        $res = run_cmd(
            escapeshellarg($tar) . ' -a -c -f ' . escapeshellarg($file) . ' -C ' . escapeshellarg($path) . ' .',
            300
        );
        if ($res['code'] !== 0 || !is_file($file)) {
            @unlink($file);
            return ['ok' => false, 'message' => '打包失败：' . ($res['error'] ?: mb_substr($res['output'], 0, 200))];
        }
        $count = 0;
        if (class_exists('PharData')) {
            try {
                $count = iterator_count(new RecursiveIteratorIterator(new PharData($file)));
            } catch (Throwable $e) {
                $count = 0;
            }
        }
    }

    $size = (int)@filesize($file);
    $encNote = '';
    if ($password !== '') {
        $encFile = $file . '.enc';
        $enc = backup_encrypt_file($file, $encFile, $password);
        if (!$enc['ok']) {
            @unlink($encFile);
            return ['ok' => false, 'message' => $enc['message']];
        }
        @unlink($file);
        $file = $encFile;
        $size = (int)@filesize($file);
        $encNote = '，已 AES-256-GCM 加密';
    }
    backup_prune();
    return [
        'ok' => true,
        'message' => '站点备份完成' . ($count > 0 ? '，共 ' . $count . ' 个文件' : '') . '（' . bytes($size) . '）' . $encNote,
        'file' => $file,
    ];
}

/**
 * 数据库备份：导出到备份目录
 */
function backup_db_create(string $dbName, string $password = ''): array
{
    $dbName = trim($dbName);
    if ($dbName === '' || !preg_match('/^[A-Za-z0-9_\-.$]+$/', $dbName)) {
        return ['ok' => false, 'message' => '数据库名不合法'];
    }
    $file = backup_dir() . '/db_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $dbName) . '_' . date('Ymd_His') . '.sql';
    $result = mysql_dump($dbName, $file);
    if ($result['ok'] && $password !== '') {
        $encFile = $file . '.enc';
        $enc = backup_encrypt_file($file, $encFile, $password);
        if (!$enc['ok']) {
            @unlink($encFile);
            return ['ok' => false, 'message' => $enc['message']];
        }
        @unlink($file);
        $result['message'] = ($result['message'] ?? '数据库备份完成') . '，已 AES-256-GCM 加密';
        $result['file'] = $encFile;
    }
    if ($result['ok']) {
        backup_prune();
    }
    return $result;
}

/**
 * 备份加密：AES-256-GCM 分块流式加密，口令经 PBKDF2-SHA256(120k) 派生。
 * 文件格式：MAGIC(8) + salt(16) + 重复[ len(4) + iv(12) + tag(16) + ciphertext ] + len=0
 */
const BACKUP_ENC_MAGIC = 'TRSKENC1';

function backup_crypt_key(string $password, string $salt): string
{
    return hash_pbkdf2('sha256', $password, $salt, 120000, 32, true);
}

function backup_encrypt_file(string $src, string $dst, string $password): array
{
    if (strlen($password) < 8) {
        return ['ok' => false, 'message' => '加密口令至少 8 位'];
    }
    $in = @fopen($src, 'rb');
    if ($in === false) {
        return ['ok' => false, 'message' => '无法读取源文件'];
    }
    $out = @fopen($dst, 'wb');
    if ($out === false) {
        fclose($in);
        return ['ok' => false, 'message' => '无法写入加密文件'];
    }
    $salt = random_bytes(16);
    $key = backup_crypt_key($password, $salt);
    fwrite($out, BACKUP_ENC_MAGIC . $salt);
    while (($chunk = fread($in, 1048576)) !== false && $chunk !== '') {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($chunk, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            fclose($in);
            fclose($out);
            @unlink($dst);
            return ['ok' => false, 'message' => '加密失败（openssl 不可用？）'];
        }
        fwrite($out, pack('N', strlen($ct)) . $iv . $tag . $ct);
    }
    fwrite($out, pack('N', 0));
    fclose($in);
    fclose($out);
    return ['ok' => true, 'message' => '加密完成'];
}

function backup_decrypt_file(string $src, string $dst, string $password): array
{
    $in = @fopen($src, 'rb');
    if ($in === false) {
        return ['ok' => false, 'message' => '无法读取加密备份'];
    }
    $magic = fread($in, 8);
    if ($magic !== BACKUP_ENC_MAGIC) {
        fclose($in);
        return ['ok' => false, 'message' => '不是有效的加密备份文件'];
    }
    $salt = fread($in, 16);
    if ($salt === false || strlen($salt) !== 16) {
        fclose($in);
        return ['ok' => false, 'message' => '加密备份头部损坏'];
    }
    $key = backup_crypt_key($password, $salt);
    $out = @fopen($dst, 'wb');
    if ($out === false) {
        fclose($in);
        return ['ok' => false, 'message' => '无法写入临时文件'];
    }
    while (true) {
        $lenRaw = fread($in, 4);
        if ($lenRaw === false || strlen($lenRaw) < 4) {
            fclose($in);
            fclose($out);
            @unlink($dst);
            return ['ok' => false, 'message' => '加密备份不完整'];
        }
        $len = unpack('N', $lenRaw)[1];
        if ($len === 0) {
            break;
        }
        $iv = fread($in, 12);
        $tag = fread($in, 16);
        $ct = $len > 0 ? fread($in, $len) : '';
        if ($iv === false || $tag === false || $ct === false || strlen($ct) !== $len) {
            fclose($in);
            fclose($out);
            @unlink($dst);
            return ['ok' => false, 'message' => '加密备份数据被截断'];
        }
        $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            fclose($in);
            fclose($out);
            @unlink($dst);
            return ['ok' => false, 'message' => '解密失败：口令错误或文件已损坏'];
        }
        fwrite($out, $plain);
    }
    fclose($in);
    fclose($out);
    return ['ok' => true, 'message' => '解密完成'];
}

/**
 * 若备份是加密的，解密到临时文件；返回 [file, tempToClean]
 */
function backup_prepare_readable(string $file, string $password): array
{
    if (!str_ends_with(strtolower($file), '.enc')) {
        return ['ok' => true, 'file' => $file, 'temp' => null];
    }
    if ($password === '') {
        return ['ok' => false, 'message' => '该备份已加密，请输入解密口令'];
    }
    $inner = substr(basename($file), 0, -4);
    $ext = strtolower(pathinfo($inner, PATHINFO_EXTENSION));
    $tmpDir = storage_path('tmp');
    if (!is_dir($tmpDir)) {
        @mkdir($tmpDir, 0777, true);
    }
    $tmp = $tmpDir . '/restore_' . bin2hex(random_bytes(6)) . '.' . ($ext === 'sql' ? 'sql' : 'zip');
    $r = backup_decrypt_file($file, $tmp, $password);
    if (!$r['ok']) {
        return $r;
    }
    return ['ok' => true, 'file' => $tmp, 'temp' => $tmp];
}

/**
 * 删除备份文件（含加密包）
 */
function backup_delete(string $name): array
{
    $file = backup_resolve($name);
    if ($file === null) {
        return ['ok' => false, 'message' => '备份文件不存在或不在备份目录内'];
    }
    if (!@unlink($file)) {
        return ['ok' => false, 'message' => '删除失败，文件可能被占用'];
    }
    return ['ok' => true, 'message' => '已删除 ' . basename($file)];
}

function backup_resolve(string $name): ?string
{
    $base = basename(str_replace('\\', '/', $name));
    if ($base === '' || !preg_match('/^[A-Za-z0-9._-]+\.(zip|sql)(\.enc)?$/i', $base)) {
        return null;
    }
    $file = backup_dir() . '/' . $base;
    return is_file($file) ? $file : null;
}

/**
 * 站点恢复：把备份 zip 解压覆盖到站点目录（拒绝路径穿越）
 */
function backup_site_restore(string $name, int $siteId, string $password = ''): array
{
    $resolved = backup_resolve($name);
    if ($resolved === null) {
        return ['ok' => false, 'message' => '备份文件不存在'];
    }
    $prep = backup_prepare_readable($resolved, $password);
    if (!$prep['ok']) {
        return $prep;
    }
    try {
        return backup_site_restore_from($prep['file'], $siteId);
    } finally {
        if (!empty($prep['temp'])) {
            @unlink($prep['temp']);
        }
    }
}

function backup_site_restore_from(string $file, int $siteId): array
{
    $site = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$siteId]);
    if (!$site) {
        return ['ok' => false, 'message' => '站点不存在'];
    }
    $path = rtrim(str_replace('\\', '/', (string)$site['path']), '/');
    if (!is_dir($path)) {
        return ['ok' => false, 'message' => '站点目录不存在：' . $path];
    }

    if (!backup_zip_available()) {
        $tar = backup_tar_bin();
        if ($tar === '') {
            return ['ok' => false, 'message' => '当前 PHP 未启用 zip 扩展，且系统未找到 tar，无法解压备份'];
        }
        $res = run_cmd(
            escapeshellarg($tar) . ' -x -f ' . escapeshellarg($file) . ' -C ' . escapeshellarg($path),
            300
        );
        return [
            'ok' => $res['code'] === 0,
            'message' => $res['code'] === 0
                ? '已解压备份到 ' . $path
                : ('解压失败：' . ($res['error'] ?: mb_substr($res['output'], 0, 200))),
        ];
    }

    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        return ['ok' => false, 'message' => '无法打开备份文件'];
    }
    $written = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = str_replace('\\', '/', (string)$zip->getNameIndex($i));
        if ($entryName === '' || str_starts_with($entryName, '/') || preg_match('#(^|/)\.\.(/|$)#', $entryName)) {
            continue;
        }
        $target = $path . '/' . $entryName;
        if (str_ends_with($entryName, '/')) {
            @mkdir($target, 0777, true);
            continue;
        }
        $dir = dirname($target);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $stream = $zip->getStream($entryName);
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
    return ['ok' => true, 'message' => '已恢复 ' . $written . ' 个文件到 ' . $path];
}

/**
 * 数据库恢复：把 .sql 导入指定库（会覆盖同名表数据）
 */
function backup_db_restore(string $name, string $dbName, string $password = ''): array
{
    $resolved = backup_resolve($name);
    if ($resolved === null) {
        return ['ok' => false, 'message' => '备份文件不存在'];
    }
    $prep = backup_prepare_readable($resolved, $password);
    if (!$prep['ok']) {
        return $prep;
    }
    try {
        return backup_db_restore_from($prep['file'], $dbName);
    } finally {
        if (!empty($prep['temp'])) {
            @unlink($prep['temp']);
        }
    }
}

function backup_db_restore_from(string $file, string $dbName): array
{
    $dbName = trim($dbName);
    if ($dbName === '' || !preg_match('/^[A-Za-z0-9_\-.$]+$/', $dbName)) {
        return ['ok' => false, 'message' => '数据库名不合法'];
    }

    $mysql = find_mysql_bin();
    $bin = $mysql !== '' ? $mysql . '/mysql.exe' : 'mysql';
    if (PHP_OS_FAMILY !== 'Windows') {
        $bin = 'mysql';
    } elseif (!is_file($bin)) {
        return ['ok' => false, 'message' => '未找到 mysql 客户端，请确认 MySQL 安装路径'];
    }

    $creds = mysql_admin_credentials();
    $db = config('database');
    $ini = storage_path('tmp/mysql_' . bin2hex(random_bytes(4)) . '.cnf');
    file_put_contents(
        $ini,
        "[client]\nhost=\"" . $db['host'] . "\"\nport=" . $db['port']
        . "\nuser=\"" . $creds['user'] . "\"\npassword=\"" . str_replace('"', '', $creds['pass']) . "\"\n"
    );
    $command = sprintf(
        '%s --defaults-extra-file=%s %s < %s 2>&1',
        escapeshellarg($bin),
        escapeshellarg($ini),
        escapeshellarg($dbName),
        escapeshellarg($file)
    );
    $result = run_cmd($command, 180);
    @unlink($ini);

    $ok = $result['code'] === 0 && stripos($result['output'], 'ERROR') === false;
    return [
        'ok' => $ok,
        'message' => $ok
            ? '数据库 ' . $dbName . ' 已从备份恢复'
            : ('恢复失败：' . ($result['error'] ?: mb_substr($result['output'], 0, 200))),
    ];
}

/**
 * 保留策略：按「类型 + 目标」分组，只保留最近 N 份
 */
function backup_prune(): void
{
    $keep = max(1, (int)setting('backup_retention', '7'));
    $groups = [];
    foreach (backup_list() as $item) {
        $key = $item['kind'] . ':' . $item['target'];
        $groups[$key][] = $item;
    }
    foreach ($groups as $items) {
        foreach (array_slice($items, $keep) as $old) {
            @unlink($old['file']);
        }
    }
}
