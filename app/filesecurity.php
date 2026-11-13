<?php
declare(strict_types=1);

/**
 * 文件安全：完整性基线（防篡改）与 Webshell / 木马特征扫描
 * 只读站点目录，不改动任何业务文件。
 */

function fs_scan_dir(): string
{
    $dir = storage_path('scan');
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return str_replace('\\', '/', $dir);
}

function fs_baseline_path(string $site): string
{
    return fs_scan_dir() . '/baseline_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $site) . '.json';
}

function fs_result_path(string $site): string
{
    return fs_scan_dir() . '/result_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $site) . '.json';
}

function fs_skip_dirs(): array
{
    return ['.git', '.svn', '.idea', '.vscode', 'node_modules'];
}

/**
 * 脚本类扩展名（参与内容扫描）
 */
function fs_script_exts(): array
{
    return ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'inc', 'tpl', 'js', 'html', 'htm', 'txt'];
}

/**
 * 遍历目录，返回相对路径 => [size, mtime, hash]
 * 只对不超过 $hashLimit 的脚本类文件计算 sha256，其余记录大小与时间以控制耗时。
 */
function fs_collect(string $root, int $hashLimit = 2097152, int $maxFiles = 20000): array
{
    $root = str_replace('\\', '/', $root);
    $files = [];
    if (!is_dir($root)) {
        return $files;
    }
    $scriptExts = fs_script_exts();
    $skip = fs_skip_dirs();
    $queue = [$root];
    $count = 0;

    while ($queue && $count < $maxFiles) {
        $dir = array_pop($queue);
        $handle = @opendir($dir);
        if (!$handle) {
            continue;
        }
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            if (is_dir($full)) {
                if (!in_array($entry, $skip, true)) {
                    $queue[] = $full;
                }
                continue;
            }
            if (!is_file($full)) {
                continue;
            }
            $count++;
            if ($count > $maxFiles) {
                break;
            }
            $rel = ltrim(str_replace($root, '', $full), '/');
            $size = (int)@filesize($full);
            $mtime = (int)@filemtime($full);
            $ext = strtolower((string)pathinfo($entry, PATHINFO_EXTENSION));
            $hash = '';
            if ($size <= $hashLimit && in_array($ext, $scriptExts, true)) {
                $hash = (string)@hash_file('sha256', $full);
            }
            $files[$rel] = ['size' => $size, 'mtime' => $mtime, 'hash' => $hash];
        }
        closedir($handle);
    }
    ksort($files);
    return $files;
}

/**
 * 生成或更新基线
 */
function fs_baseline_create(string $site, string $root): array
{
    if (!is_dir($root)) {
        return ['ok' => false, 'message' => '站点目录不存在：' . $root];
    }
    $files = fs_collect($root);
    if (!$files) {
        return ['ok' => false, 'message' => '目录为空或无法读取，未生成基线'];
    }
    $payload = [
        'site' => $site,
        'root' => str_replace('\\', '/', $root),
        'created_at' => now(),
        'file_count' => count($files),
        'files' => $files,
    ];
    if (@file_put_contents(fs_baseline_path($site), json_encode($payload, JSON_UNESCAPED_UNICODE)) === false) {
        return ['ok' => false, 'message' => '基线写入失败，请检查 storage/scan 权限'];
    }
    log_action('生成文件完整性基线', $site . ' · ' . count($files) . ' 个文件', 'warning');
    return ['ok' => true, 'message' => '基线已生成：' . count($files) . ' 个文件', 'count' => count($files)];
}

function fs_baseline_meta(string $site): ?array
{
    $path = fs_baseline_path($site);
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string)@file_get_contents($path), true);
    if (!is_array($data)) {
        return null;
    }
    return [
        'created_at' => (string)($data['created_at'] ?? ''),
        'file_count' => (int)($data['file_count'] ?? 0),
        'root' => (string)($data['root'] ?? ''),
        'size' => (int)@filesize($path),
    ];
}

/**
 * 与基线比对：新增 / 修改 / 删除
 */
function fs_compare(string $site, string $root, int $limit = 300): array
{
    $path = fs_baseline_path($site);
    if (!is_file($path)) {
        return ['ok' => false, 'message' => '还没有基线，请先建立基线'];
    }
    $data = json_decode((string)@file_get_contents($path), true);
    $baseline = is_array($data['files'] ?? null) ? $data['files'] : [];
    $current = fs_collect($root);

    $added = [];
    $modified = [];
    $removed = [];
    foreach ($current as $rel => $meta) {
        if (!isset($baseline[$rel])) {
            $added[] = ['path' => $rel, 'size' => $meta['size'], 'mtime' => $meta['mtime']];
            continue;
        }
        $old = $baseline[$rel];
        $hashDiff = $old['hash'] !== '' && $meta['hash'] !== '' && $old['hash'] !== $meta['hash'];
        $sizeDiff = (int)$old['size'] !== (int)$meta['size'];
        if ($hashDiff || ($old['hash'] === '' && $sizeDiff)) {
            $modified[] = [
                'path' => $rel,
                'size' => $meta['size'],
                'old_size' => (int)$old['size'],
                'mtime' => $meta['mtime'],
            ];
        }
    }
    foreach ($baseline as $rel => $meta) {
        if (!isset($current[$rel])) {
            $removed[] = ['path' => $rel, 'size' => (int)$meta['size']];
        }
    }

    $result = [
        'ok' => true,
        'message' => '',
        'kind' => 'compare',
        'site' => $site,
        'root' => str_replace('\\', '/', $root),
        'baseline_at' => (string)($data['created_at'] ?? ''),
        'run_at' => now(),
        'total' => count($current),
        'added' => array_slice($added, 0, $limit),
        'modified' => array_slice($modified, 0, $limit),
        'removed' => array_slice($removed, 0, $limit),
        'counts' => ['added' => count($added), 'modified' => count($modified), 'removed' => count($removed)],
    ];
    $result['clean'] = $result['counts'] === ['added' => 0, 'modified' => 0, 'removed' => 0];
    @file_put_contents(fs_result_path($site), json_encode($result, JSON_UNESCAPED_UNICODE));
    return $result;
}

/**
 * Webshell / 木马特征规则：高置信度组合，避免误报大量正常代码
 */
function fs_malware_rules(): array
{
    return [
        ['id' => 'eval-superglobal', 'level' => 'high', 'label' => 'eval 执行外部输入',
            'pattern' => '/\beval\s*\(\s*@?\$_(?:POST|GET|REQUEST|COOKIE|SERVER)/i'],
        ['id' => 'assert-superglobal', 'level' => 'high', 'label' => 'assert 执行外部输入',
            'pattern' => '/\bassert\s*\(\s*@?\$_(?:POST|GET|REQUEST|COOKIE)/i'],
        ['id' => 'decode-superglobal', 'level' => 'high', 'label' => '解码外部输入后执行',
            'pattern' => '/\b(?:base64_decode|gzinflate|gzuncompress|str_rot13|strrev)\s*\(\s*@?\$_(?:POST|GET|REQUEST|COOKIE)/i'],
        ['id' => 'exec-superglobal', 'level' => 'high', 'label' => '命令执行函数接外部输入',
            'pattern' => '/\b(?:system|exec|shell_exec|passthru|popen|proc_open|pcntl_exec)\s*\([^;]{0,120}\$_(?:POST|GET|REQUEST|COOKIE)/i'],
        ['id' => 'include-superglobal', 'level' => 'high', 'label' => '动态包含外部输入',
            'pattern' => '/\b(?:include|include_once|require|require_once)\s*\(?\s*@?\$_(?:POST|GET|REQUEST|COOKIE)/i'],
        ['id' => 'filewrite-superglobal', 'level' => 'high', 'label' => '写文件内容来自外部输入',
            'pattern' => '/\b(?:file_put_contents|fwrite|fputs)\s*\([^;]{0,80}\$_(?:POST|GET|REQUEST|COOKIE)/i'],
        ['id' => 'variable-function', 'level' => 'medium', 'label' => '变量函数调用（$f(...)）',
            'pattern' => '/\$_(?:POST|GET|REQUEST|COOKIE)\s*\[[^\]]{1,40}\]\s*\(/i'],
        ['id' => 'preg-e', 'level' => 'high', 'label' => 'preg_replace /e 修饰符',
            'pattern' => '/preg_replace\s*\(\s*[\'"][^\'"]*\/[a-z]*e[a-z]*[\'"]/i'],
        ['id' => 'create-function', 'level' => 'medium', 'label' => 'create_function 动态代码',
            'pattern' => '/\bcreate_function\s*\(/i'],
        ['id' => 'webshell-name', 'level' => 'high', 'label' => '已知 Webshell 特征串',
            'pattern' => '/(?:FilesMan|WSO\s*[0-9.]|c99shell|r57shell|b374k|phpspy|weevely|china\s*chopper|%PDF-1\.4\s*<\?php)/i'],
        ['id' => 'php-in-upload', 'level' => 'high', 'label' => '非 PHP 扩展名文件内含 PHP 代码',
            'pattern' => '/<\?php/i', 'only_ext' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'ico', 'svg', 'zip', 'mp4', 'mp3', 'pdf', 'txt']],
        ['id' => 'oob-curl-post', 'level' => 'medium', 'label' => 'curl 携带外部输入',
            'pattern' => '/curl_setopt[^;]{0,200}CURLOPT_(?:POSTFIELDS|URL)[^;]{0,200}\$_(?:POST|GET|REQUEST)/is'],
    ];
}

/**
 * 扫描站点目录内的脚本文件
 */
function fs_malware_scan(string $site, string $root, int $maxFiles = 8000, int $maxBytes = 1048576, int $limit = 300): array
{
    $root = str_replace('\\', '/', $root);
    if (!is_dir($root)) {
        return ['ok' => false, 'message' => '站点目录不存在：' . $root];
    }
    $rules = fs_malware_rules();
    $scriptExts = fs_script_exts();
    $skip = fs_skip_dirs();
    $hits = [];
    $scanned = 0;
    $skipped = 0;
    $unreadable = [];
    $queue = [$root];

    while ($queue && $scanned < $maxFiles) {
        $dir = array_pop($queue);
        $handle = @opendir($dir);
        if (!$handle) {
            continue;
        }
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            if (is_dir($full)) {
                if (!in_array($entry, $skip, true)) {
                    $queue[] = $full;
                }
                continue;
            }
            if (!is_file($full)) {
                continue;
            }
            $size = (int)@filesize($full);
            if ($size === 0 || $size > $maxBytes) {
                $skipped++;
                continue;
            }
            $ext = strtolower((string)pathinfo($entry, PATHINFO_EXTENSION));
            if (!in_array($ext, $scriptExts, true) && !in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'ico', 'svg', 'zip', 'pdf'], true)) {
                $skipped++;
                continue;
            }
            $content = @file_get_contents($full);
            $rel0 = ltrim(str_replace($root, '', $full), '/');
            if ($content === false) {
                // 读不出来本身就是可疑信号（权限异常 / 被杀软锁定 / 特殊属性）
                if (count($unreadable) < 50) {
                    $unreadable[] = $rel0;
                }
                $skipped++;
                continue;
            }
            if ($content === '') {
                $skipped++;
                continue;
            }
            $scanned++;
            $rel = $rel0;
            $lines = preg_split('/\r?\n/', $content) ?: [];
            foreach ($rules as $rule) {
                // only_ext 规则只针对特定扩展（如伪装成图片的 PHP）
                if (isset($rule['only_ext']) && !in_array($ext, $rule['only_ext'], true)) {
                    continue;
                }
                foreach ($lines as $i => $line) {
                    if ($line === '' || strlen($line) > 4000) {
                        continue;
                    }
                    if (preg_match($rule['pattern'], $line)) {
                        $hits[] = [
                            'path' => $rel,
                            'line' => $i + 1,
                            'rule' => $rule['label'],
                            'rule_id' => $rule['id'],
                            'level' => $rule['level'],
                            'snippet' => mb_substr(trim($line), 0, 220),
                            'size' => $size,
                            'mtime' => (int)@filemtime($full),
                        ];
                        break 2; // 同一文件只报一条，避免刷屏
                    }
                }
            }
            if (count($hits) >= $limit) {
                break 2;
            }
        }
        closedir($handle);
    }

    usort($hits, static fn($a, $b) => ($a['level'] === $b['level']) ? strcmp($a['path'], $b['path']) : ($a['level'] === 'high' ? -1 : 1));

    $result = [
        'ok' => true,
        'message' => '',
        'kind' => 'scan',
        'site' => $site,
        'root' => $root,
        'run_at' => now(),
        'scanned' => $scanned,
        'skipped' => $skipped,
        'unreadable' => $unreadable,
        'hits' => $hits,
        'high' => count(array_filter($hits, static fn($h) => $h['level'] === 'high')),
        'medium' => count(array_filter($hits, static fn($h) => $h['level'] === 'medium')),
    ];
    @file_put_contents(fs_result_path($site), json_encode($result, JSON_UNESCAPED_UNICODE));
    return $result;
}

function fs_last_result(string $site): ?array
{
    $path = fs_result_path($site);
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? $data : null;
}
