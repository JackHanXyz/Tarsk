<?php
declare(strict_types=1);

/**
 * 在线升级：检查 GitHub 最新提交、下载源码包、安全覆盖并保留配置与数据
 */

function updater_repo(): string
{
    return 'JackHanXyz/Tarsk';
}

function updater_branch(): string
{
    $branch = trim((string)setting('update_branch', 'main'));
    return $branch !== '' ? $branch : 'main';
}

function updater_remote_url(): string
{
    return 'https://codeload.github.com/' . updater_repo() . '/zip/refs/heads/' . updater_branch();
}

/**
 * 本地代码版本：优先读 .git/HEAD，其次 storage/version.json，最后回落 config 版本号
 */
function updater_local_sha(): string
{
    $gitDir = app_root() . '/.git';
    $head = @file_get_contents($gitDir . '/HEAD');
    if ($head !== false && trim($head) !== '') {
        $head = trim($head);
        if (str_starts_with($head, 'ref: ')) {
            $ref = trim(substr($head, 5));
            $sha = @file_get_contents($gitDir . '/' . $ref);
            if ($sha !== false) {
                return trim($sha);
            }
        } elseif (preg_match('/^[0-9a-f]{40}$/', $head)) {
            return $head;
        }
    }
    $info = updater_state();
    return (string)($info['sha'] ?? '');
}

function updater_state(): array
{
    $file = storage_path('version.json');
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string)@file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function updater_state_save(string $sha, string $branch): void
{
    @file_put_contents(
        storage_path('version.json'),
        json_encode(['sha' => $sha, 'branch' => $branch, 'updated_at' => now()], JSON_UNESCAPED_UNICODE)
    );
}

function updater_local_version(): string
{
    $version = (string)config('app.version', '1.0.0');
    $sha = updater_local_sha();
    return $version . ($sha !== '' ? ' · ' . substr($sha, 0, 7) : '');
}

/**
 * HTTP 请求（curl 优先，支持可选代理）
 */
function updater_http(string $url, array $headers = [], int $timeout = 20): array
{
    $proxy = trim((string)setting('update_proxy', ''));
    $headers[] = 'User-Agent: Tarsk-Updater/1.0';
    $headers[] = 'Accept: application/vnd.github+json';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ];
        if ($proxy !== '') {
            $opts[CURLOPT_PROXY] = $proxy;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return ['ok' => $code >= 200 && $code < 300 && $body !== false, 'code' => $code, 'body' => (string)$body, 'error' => $error];
    }

    $headerText = implode("\r\n", $headers) . "\r\n";
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => $headerText,
        'timeout' => $timeout,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $code = (int)$m[1];
        }
    }
    return ['ok' => $body !== false && $code >= 200 && $code < 300, 'code' => $code, 'body' => (string)$body, 'error' => $body === false ? '请求失败' : ''];
}

/**
 * 查询远端最新提交（带 10 分钟缓存）
 */
function updater_remote(bool $force = false): array
{
    $cache = json_decode((string)setting('update_cache', ''), true);
    if (!$force && is_array($cache) && !empty($cache['checked_at']) && time() - strtotime((string)$cache['checked_at']) < 600) {
        return $cache;
    }

    $url = 'https://api.github.com/repos/' . updater_repo() . '/commits/' . updater_branch();
    $res = updater_http($url, ['Accept: application/vnd.github+json'], 20);
    if (!$res['ok']) {
        $data = [
            'ok' => false,
            'message' => '无法访问 GitHub（HTTP ' . $res['code'] . '）：' . ($res['error'] ?: '请检查服务器网络，或在下方填写代理地址'),
            'checked_at' => now(),
        ];
        setting_set('update_cache', json_encode($data, JSON_UNESCAPED_UNICODE));
        return $data;
    }
    $json = json_decode($res['body'], true);
    if (!is_array($json) || empty($json['sha'])) {
        return ['ok' => false, 'message' => '获取版本信息失败：返回内容无法解析', 'checked_at' => now()];
    }
    $data = [
        'ok' => true,
        'sha' => (string)$json['sha'],
        'short' => substr((string)$json['sha'], 0, 7),
        'message' => (string)($json['commit']['message'] ?? ''),
        'date' => (string)($json['commit']['committer']['date'] ?? ''),
        'html' => (string)($json['html_url'] ?? ''),
        'checked_at' => now(),
    ];
    setting_set('update_cache', json_encode($data, JSON_UNESCAPED_UNICODE));
    return $data;
}

/**
 * 检查是否有新版本
 */
function updater_check(bool $force = false): array
{
    $remote = updater_remote($force);
    if (empty($remote['ok'])) {
        return ['ok' => false, 'message' => (string)($remote['message'] ?? '检查失败'), 'remote' => $remote, 'local' => updater_local_sha()];
    }
    $local = updater_local_sha();
    $hasUpdate = $local === '' ? true : ($local !== $remote['sha']);
    return [
        'ok' => true,
        'has_update' => $hasUpdate,
        'local' => $local,
        'local_version' => updater_local_version(),
        'remote' => $remote,
        'message' => $hasUpdate ? '发现新版本：' . $remote['short'] . '（' . mb_substr((string)$remote['message'], 0, 80) . '）' : '已是最新版本',
    ];
}

/**
 * 下载源码包并解压到临时区，返回暂存目录
 */
function updater_stage(): array
{
    $tmp = storage_path('tmp/update_' . date('YmdHis'));
    if (!is_dir($tmp)) {
        @mkdir($tmp, 0777, true);
    }
    $zip = $tmp . '/source.zip';

    $proxy = trim((string)setting('update_proxy', ''));
    if (function_exists('curl_init')) {
        $ch = curl_init(updater_remote_url());
        $opts = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FILE => fopen($zip, 'wb'),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Tarsk-Updater/1.0',
        ];
        if ($proxy !== '') {
            $opts[CURLOPT_PROXY] = $proxy;
        }
        curl_setopt_array($ch, $opts);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!$ok || $code < 200 || $code >= 300) {
            @unlink($zip);
            return ['ok' => false, 'message' => '下载源码失败（HTTP ' . $code . '）：' . $error];
        }
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 300, 'user_agent' => 'Tarsk-Updater/1.0']]);
        $body = @file_get_contents(updater_remote_url(), false, $ctx);
        if ($body === false) {
            return ['ok' => false, 'message' => '下载源码失败，请检查网络或配置代理'];
        }
        file_put_contents($zip, $body);
    }

    if (!is_file($zip) || filesize($zip) < 1000) {
        @unlink($zip);
        return ['ok' => false, 'message' => '下载的源码包无效'];
    }

    $out = $tmp . '/src';
    @mkdir($out, 0777, true);
    if (class_exists('ZipArchive')) {
        $za = new ZipArchive();
        if ($za->open($zip) !== true) {
            return ['ok' => false, 'message' => '无法解压源码包'];
        }
        $za->extractTo($out);
        $za->close();
    } else {
        $tar = function_exists('backup_tar_bin') ? backup_tar_bin() : '';
        if ($tar === '') {
            return ['ok' => false, 'message' => '当前 PHP 未启用 zip 扩展且未找到 tar，无法解压更新包'];
        }
        $res = run_cmd(escapeshellarg($tar) . ' -x -f ' . escapeshellarg($zip) . ' -C ' . escapeshellarg($out), 300);
        if ($res['code'] !== 0) {
            return ['ok' => false, 'message' => '解压失败：' . ($res['error'] ?: $res['output'])];
        }
    }

    $src = null;
    foreach (@scandir($out) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (is_dir($out . '/' . $entry) && is_file($out . '/' . $entry . '/public/index.php')) {
            $src = $out . '/' . $entry;
            break;
        }
    }
    if ($src === null) {
        return ['ok' => false, 'message' => '源码包结构异常，未找到 public/index.php'];
    }
    return ['ok' => true, 'src' => $src, 'zip' => $zip];
}

/**
 * 应用更新：备份当前代码 → 覆盖源码（保留 config / storage / install.lock / .git）
 *
 * @param bool $apply false 时只做下载与校验（dry-run）
 */
function updater_apply(bool $apply = true): array
{
    $stage = updater_stage();
    if (!$stage['ok']) {
        return ['ok' => false, 'message' => $stage['message']];
    }
    $src = (string)$stage['src'];
    $root = app_root();

    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $count++;
        }
    }

    if (!$apply) {
        $stageDir = dirname(dirname($src));
        if (is_dir($stageDir)) {
            @delete_path($stageDir);
        }
        return ['ok' => true, 'dry_run' => true, 'files' => $count, 'message' => '校验通过，共 ' . $count . ' 个文件（dry-run，未写入）'];
    }

    // 备份当前代码
    $backup = '';
    if (function_exists('backup_zip_available') && backup_zip_available()) {
        $backup = backup_dir() . '/code_' . date('Ymd_His') . '.zip';
        $za = new ZipArchive();
        if ($za->open($backup, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach (['app', 'bin', 'public'] as $dir) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($it as $f) {
                    if ($f->isFile()) {
                        $za->addFile($f->getPathname(), $dir . '/' . substr(str_replace('\\', '/', $f->getPathname()), strlen(str_replace('\\', '/', $root)) + 1));
                    }
                }
            }
            $za->close();
        }
    }

    $skipTop = ['config.php', 'install.lock', 'storage', '.git', '.env'];
    $written = 0;
    $errors = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($src) + 1));
        if ($rel === '' || preg_match('#(^|/)\.\.(/|$)#', $rel)) {
            continue;
        }
        $top = explode('/', $rel)[0];
        if (in_array($top, $skipTop, true)) {
            continue;
        }
        $target = $root . '/' . $rel;
        $dir = dirname($target);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (!@copy($file->getPathname(), $target)) {
            $errors[] = $rel;
        } else {
            $written++;
        }
    }

    if ($errors) {
        return [
            'ok' => false,
            'message' => '更新未完全成功，已写入 ' . $written . ' 个文件，失败 ' . count($errors) . ' 个：'
                . implode('、', array_slice($errors, 0, 5)) . ($backup !== '' ? '；旧代码备份：' . $backup : ''),
            'backup' => $backup,
        ];
    }

    $remote = updater_remote(true);
    $sha = (string)($remote['sha'] ?? '');
    if ($sha !== '') {
        updater_state_save($sha, updater_branch());
    }
    // 清理暂存目录
    $stageDir = dirname(dirname((string)$stage['src']));
    if (is_dir($stageDir)) {
        @delete_path($stageDir);
    }

    return [
        'ok' => true,
        'message' => '已更新 ' . $written . ' 个文件，请重启面板进程使新代码生效'
            . ($backup !== '' ? '（旧代码已备份到 ' . basename($backup) . '）' : ''),
        'backup' => $backup,
        'files' => $written,
    ];
}
