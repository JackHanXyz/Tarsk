<?php
declare(strict_types=1);

/**
 * 环境配置层：PHP ini / PHP 扩展 / Nginx 全局 / MySQL 运行参数 / Redis 数据浏览
 *
 * 统一原则：任何写入都走「备份 → 自校验 → 落盘 → 重载」，校验失败自动回滚，
 * 不让面板把用户的运行环境改坏。
 */

/* ============================== 通用 ============================== */

function env_backup_dir(): string
{
    $dir = storage_path('backups/env');
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return str_replace('\\', '/', $dir);
}

function env_backup(string $path, string $label): ?string
{
    if (!is_file($path)) {
        return null;
    }
    $base = basename($path);
    $file = env_backup_dir() . '/' . $base . '.' . preg_replace('/[^A-Za-z0-9_-]/', '', $label)
        . '.' . date('Ymd-His') . '.bak';
    if (!@copy($path, $file)) {
        return null;
    }
    $files = glob(env_backup_dir() . '/' . $base . '.' . preg_replace('/[^A-Za-z0-9_-]/', '', $label) . '.*.bak') ?: [];
    sort($files);
    while (count($files) > 20) {
        @unlink((string)array_shift($files));
    }
    return $file;
}

function env_backup_list(string $path, string $label): array
{
    $base = basename($path);
    $files = glob(env_backup_dir() . '/' . $base . '.' . preg_replace('/[^A-Za-z0-9_-]/', '', $label) . '.*.bak') ?: [];
    rsort($files);
    return array_map(static function (string $file): array {
        return [
            'file' => $file,
            'name' => basename($file),
            'time' => date('Y-m-d H:i:s', (int)@filemtime($file)),
            'size' => (int)@filesize($file),
        ];
    }, $files);
}

/* ============================ PHP ini ============================ */

function php_ini_path(string $key): ?string
{
    if (!str_starts_with($key, 'php') || !isset(runtime_catalog()[$key])) {
        return null;
    }
    $root = runtime_root($key);
    return is_file($root . '/php.ini') ? $root . '/php.ini' : null;
}

function php_ini_key(string $version): string
{
    return runtime_php_key($version);
}

function php_ini_read(string $key): string
{
    $path = php_ini_path($key);
    if ($path === null) {
        return '';
    }
    return (string)@file_get_contents($path);
}

function ini_get_value(string $text, string $key, string $default = ''): string
{
    $found = $default;
    foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === ';' || $line[0] === '#') {
            continue;
        }
        if (preg_match('/^' . preg_quote($key, '/') . '\s*=\s*(.*)$/i', $line, $m)) {
            $found = ini_unquote($m[1]);
        }
    }
    return $found;
}

function ini_get_values(string $text, string $key): array
{
    $values = [];
    foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === ';' || $line[0] === '#') {
            continue;
        }
        if (preg_match('/^' . preg_quote($key, '/') . '\s*=\s*(.*)$/i', $line, $m)) {
            $values[] = ini_unquote($m[1]);
        }
    }
    return $values;
}

function ini_unquote(string $value): string
{
    $value = trim($value);
    if (strlen($value) > 1 && $value[0] === '"' && substr($value, -1) === '"') {
        return substr($value, 1, -1);
    }
    return $value;
}

/**
 * 新增/覆盖一条指令：先删除同名行，再插入到全局作用域（第一个非 [PHP] 小节之前）
 */
function ini_set_directive(string $text, string $key, ?string $value): string
{
    $lines = preg_split('/\r?\n/', $text) ?: [];
    if ($lines === [] || (count($lines) === 1 && $lines[0] === '')) {
        $lines = [];
    }
    $pattern = '/^\s*' . preg_quote($key, '/') . '\s*=/i';
    $out = [];
    foreach ($lines as $line) {
        if (preg_match($pattern, $line)) {
            continue;
        }
        $out[] = $line;
    }
    if ($value !== null) {
        $index = ini_global_scope_index($out);
        array_splice($out, $index, 0, [$key . ' = ' . $value]);
    }
    return rtrim(implode("\n", $out), "\n") . "\n";
}

function ini_global_scope_index(array $lines): int
{
    foreach ($lines as $i => $line) {
        $t = trim($line);
        if ($t !== '' && $t[0] === '[' && !preg_match('/^\[\s*php\s*\]$/i', $t)) {
            return $i;
        }
    }
    return count($lines);
}

function ini_syntax_errors(string $text): array
{
    $errors = [];
    foreach (preg_split('/\r?\n/', $text) ?: [] as $i => $raw) {
        $line = trim($raw);
        if ($line === '' || $line[0] === ';' || $line[0] === '#') {
            continue;
        }
        if ($line[0] === '[') {
            if (!preg_match('/^\[[^\[\]]+\]\s*(;.*)?$/', $line)) {
                $errors[] = ['line' => $i + 1, 'message' => '小节标题格式不正确'];
            }
            continue;
        }
        if (!preg_match('/^[A-Za-z0-9_.\-]+\s*=/', $line)) {
            $errors[] = ['line' => $i + 1, 'message' => '不是合法的 key = value 形式'];
            continue;
        }
        if (preg_match('/=\s*"([^"]*)$/', $line)) {
            $errors[] = ['line' => $i + 1, 'message' => '引号未闭合'];
        }
    }
    return $errors;
}

/**
 * 常用参数的分组定义：type = size | int | bool | enum | text
 */
function php_ini_knob_groups(): array
{
    return [
        '资源与上传' => [
            'memory_limit' => ['type' => 'size', 'label' => '脚本内存上限', 'default' => '512M', 'hint' => '单个 PHP 进程可用内存，Laravel / WordPress 建议 256M~512M'],
            'max_execution_time' => ['type' => 'int', 'label' => '最大执行时间（秒）', 'default' => '300', 'hint' => '导入大数据量时建议 0（不限制）'],
            'max_input_time' => ['type' => 'int', 'label' => '输入解析超时（秒）', 'default' => '300', 'hint' => '-1 表示与 max_execution_time 一致'],
            'max_input_vars' => ['type' => 'int', 'label' => '单请求输入变量上限', 'default' => '1000', 'hint' => '表单字段多时（如后台批量编辑）需调大'],
            'post_max_size' => ['type' => 'size', 'label' => 'POST 数据上限', 'default' => '64M', 'hint' => '必须 ≥ 上传单文件上限'],
            'upload_max_filesize' => ['type' => 'size', 'label' => '单文件上传上限', 'default' => '64M', 'hint' => '同时需放开 Nginx 的 client_max_body_size'],
            'max_file_uploads' => ['type' => 'int', 'label' => '单请求文件数量', 'default' => '20', 'hint' => '多图上传场景可调大'],
        ],
        '错误与日志' => [
            'display_errors' => ['type' => 'bool', 'label' => '页面显示错误', 'default' => 'Off', 'hint' => '生产环境务必 Off'],
            'log_errors' => ['type' => 'bool', 'label' => '记录错误日志', 'default' => 'On', 'hint' => '写入 error_log 指定的文件'],
            'error_reporting' => ['type' => 'text', 'label' => '错误级别', 'default' => 'E_ALL & ~E_DEPRECATED & ~E_STRICT', 'hint' => '调试可填 E_ALL，生产建议保持默认'],
            'error_log' => ['type' => 'text', 'label' => '错误日志路径', 'default' => '', 'hint' => '留空则使用默认路径'],
        ],
        '会话与缓存' => [
            'output_buffering' => ['type' => 'int', 'label' => '输出缓冲（字节）', 'default' => '4096', 'hint' => '0 表示关闭，Off 亦可'],
            'zlib.output_compression' => ['type' => 'bool', 'label' => 'PHP 层 gzip', 'default' => 'Off', 'hint' => '已由 Nginx gzip 处理，一般保持 Off'],
            'session.gc_maxlifetime' => ['type' => 'int', 'label' => '会话有效期（秒）', 'default' => '1440', 'hint' => '后台登录频繁掉线时可调大'],
            'session.save_path' => ['type' => 'text', 'label' => '会话存储目录', 'default' => '', 'hint' => '留空使用安装时生成的目录'],
            'opcache.enable' => ['type' => 'bool', 'label' => 'OPcache 字节码缓存', 'default' => 'On', 'hint' => '需先加载 opcache 扩展，可显著提速'],
            'opcache.memory_consumption' => ['type' => 'int', 'label' => 'OPcache 内存（MB）', 'default' => '128', 'hint' => '大型项目建议 256 及以上'],
            'opcache.revalidate_freq' => ['type' => 'int', 'label' => 'OPcache 校验间隔（秒）', 'default' => '2', 'hint' => '0 表示每次请求都检查文件变更'],
        ],
        '时区与限制' => [
            'date.timezone' => ['type' => 'enum', 'label' => '默认时区', 'default' => 'Asia/Shanghai', 'options' => ['Asia/Shanghai', 'Asia/Hong_Kong', 'Asia/Taipei', 'Asia/Tokyo', 'UTC', 'Europe/London', 'America/New_York'], 'hint' => '影响 date() 等函数输出'],
            'default_charset' => ['type' => 'text', 'label' => '默认字符集', 'default' => 'UTF-8', 'hint' => '一般保持 UTF-8'],
            'expose_php' => ['type' => 'bool', 'label' => '暴露 PHP 版本', 'default' => 'Off', 'hint' => '响应头里的 X-Powered-By，建议 Off'],
            'short_open_tag' => ['type' => 'bool', 'label' => '短标签 <?', 'default' => 'Off', 'hint' => '老项目可能依赖，新项目保持 Off'],
        ],
    ];
}

function php_ini_knobs(string $text): array
{
    $groups = [];
    foreach (php_ini_knob_groups() as $group => $items) {
        $knobs = [];
        foreach ($items as $key => $meta) {
            $value = ini_get_value($text, $key, (string)$meta['default']);
            $knobs[] = [
                'key' => $key,
                'type' => $meta['type'],
                'label' => $meta['label'],
                'hint' => $meta['hint'] ?? '',
                'value' => $value,
                'options' => $meta['options'] ?? [],
                'set' => ini_get_value($text, $key, '') !== '',
            ];
        }
        $groups[$group] = $knobs;
    }
    return $groups;
}

function env_normalize_knob(string $type, string $value): array
{
    $value = trim($value);
    switch ($type) {
        case 'size':
            if ($value === '' || preg_match('/^(0|[1-9][0-9]*)$/', $value)) {
                return ['ok' => true, 'value' => $value === '' ? '0' : $value];
            }
            if (preg_match('/^([0-9]+)\s*([kmg])?b?$/i', $value, $m)) {
                return ['ok' => true, 'value' => $m[1] . strtoupper($m[2] ?? '')];
            }
            return ['ok' => false, 'error' => '应为数字或带单位的大小，如 64M、1G'];
        case 'int':
            if (!preg_match('/^-?[0-9]+$/', $value)) {
                return ['ok' => false, 'error' => '应为整数'];
            }
            if (abs((int)$value) > 1000000000) {
                return ['ok' => false, 'error' => '数值超出范围'];
            }
            return ['ok' => true, 'value' => (string)(int)$value];
        case 'bool':
            $lower = strtolower($value);
            if (in_array($lower, ['1', 'on', 'true', 'yes'], true)) {
                return ['ok' => true, 'value' => 'On'];
            }
            if (in_array($lower, ['0', 'off', 'false', 'no', ''], true)) {
                return ['ok' => true, 'value' => 'Off'];
            }
            return ['ok' => false, 'error' => '应为 On / Off'];
        case 'enum':
            // 候选值由调用方按 key 校验，这里只做基础净化
            return ['ok' => true, 'value' => $value];
        default:
            if (preg_match('/[\r\n]/', $value)) {
                return ['ok' => false, 'error' => '不能包含换行'];
            }
            if (mb_strlen($value) > 190) {
                return ['ok' => false, 'error' => '内容过长'];
            }
            return ['ok' => true, 'value' => trim($value, '"')];
    }
}

/**
 * 用真实 php-cgi 校验 ini 是否可用（语法 + 扩展加载）
 */
function php_ini_test(string $key, string $content): array
{
    $bin = runtime_root($key) . '/php-cgi.exe';
    if (!is_file($bin)) {
        return ['ok' => true, 'message' => '未找到 php-cgi，已跳过运行时校验（仅做了语法检查）', 'output' => ''];
    }
    $tmpDir = storage_path('tmp');
    if (!is_dir($tmpDir)) {
        @mkdir($tmpDir, 0777, true);
    }
    $tmp = $tmpDir . '/php-check-' . $key . '.ini';
    if (@file_put_contents($tmp, $content) === false) {
        return ['ok' => false, 'message' => '无法写入临时配置文件', 'output' => ''];
    }
    $result = run_cmd('"' . $bin . '" -c "' . str_replace('\\', '/', $tmp) . '" -v', 20);
    @unlink($tmp);

    $output = trim(($result['output'] ?? '') . "\n" . ($result['error'] ?? ''));
    if (preg_match('/(syntax error|Parse error|Fatal error|Invalid (?:configuration|binding))/i', $output, $m)) {
        return ['ok' => false, 'message' => '配置未被 PHP 接受：' . $m[0], 'output' => $output];
    }
    if (preg_match_all('/PHP Warning:[^\n]*/i', $output, $w)) {
        return ['ok' => true, 'message' => '配置可用，但存在告警：' . implode('；', array_slice($w[0], 0, 3)), 'output' => $output];
    }
    if (stripos($output, 'PHP') === false) {
        return ['ok' => false, 'message' => 'php-cgi 未正常返回版本信息，配置可能无效', 'output' => $output];
    }
    return ['ok' => true, 'message' => '配置校验通过', 'output' => $output];
}

/**
 * 保存 PHP ini：逐项归一化 → 语法检查 → 真实二进制校验 → 备份写入 → 重启
 */
function php_ini_apply(string $key, array $input, bool $restart = true): array
{
    $path = php_ini_path($key);
    if ($path === null) {
        return ['ok' => false, 'message' => '未找到该 PHP 版本的 php.ini'];
    }
    $original = (string)@file_get_contents($path);
    $content = $original;
    $notes = [];
    $groups = php_ini_knob_groups();

    foreach ($groups as $items) {
        foreach ($items as $name => $meta) {
            if (!array_key_exists($name, $input)) {
                continue;
            }
            $raw = (string)$input[$name];
            if ($meta['type'] === 'enum' && ($meta['options'] ?? []) && $raw !== '' && !in_array($raw, $meta['options'], true)) {
                return ['ok' => false, 'message' => $name . ' 的取值不在允许范围内'];
            }
            $norm = env_normalize_knob((string)$meta['type'], $raw);
            if (!$norm['ok']) {
                return ['ok' => false, 'message' => $name . '：' . $norm['error']];
            }
            $value = (string)$norm['value'];
            if ($value === '' && in_array($name, ['session.save_path', 'error_log'], true)) {
                continue;
            }
            // 路径类指令统一加引号，避免含空格时被 ini 截断
            if (in_array($name, ['session.save_path', 'error_log'], true) && $value !== '' && $value[0] !== '"') {
                $value = '"' . str_replace('"', '', $value) . '"';
            }
            $content = ini_set_directive($content, $name, $value === '' ? null : $value);
        }
    }

    $post = (int)env_size_bytes(ini_get_value($content, 'post_max_size', '0'));
    $upload = (int)env_size_bytes(ini_get_value($content, 'upload_max_filesize', '0'));
    if ($post > 0 && $upload > 0 && $post < $upload) {
        $notes[] = 'post_max_size 小于 upload_max_filesize，上传会失败，已自动提升 post_max_size';
        $content = ini_set_directive($content, 'post_max_size', ini_get_value($content, 'upload_max_filesize'));
    }

    return php_ini_commit($key, $content, 'php-ini', $restart, $notes);
}

function php_ini_commit(string $key, string $content, string $label, bool $restart, array $notes = []): array
{
    $path = php_ini_path($key);
    if ($path === null) {
        return ['ok' => false, 'message' => '未找到该 PHP 版本的 php.ini'];
    }
    $errors = ini_syntax_errors($content);
    if ($errors) {
        return ['ok' => false, 'message' => '第 ' . $errors[0]['line'] . ' 行' . $errors[0]['message']];
    }
    $test = php_ini_test($key, $content);
    if (!$test['ok']) {
        return ['ok' => false, 'message' => $test['message'], 'output' => $test['output'] ?? ''];
    }
    if ($test['message'] !== '配置校验通过') {
        $notes[] = $test['message'];
    }
    $backup = env_backup($path, $label);
    if (@file_put_contents($path, $content) === false) {
        return ['ok' => false, 'message' => '写入失败：' . $path];
    }
    log_action('修改 PHP 配置', runtime_package($key)['name'] . ' · ' . $path, 'warning');

    if ($restart && runtime_state($key)['running']) {
        $restartResult = runtime_restart($key);
        $notes[] = '已重启 ' . (runtime_package($key)['name'] ?? 'PHP') . '：' . $restartResult['message'];
    } elseif ($restart) {
        $notes[] = 'PHP 当前未运行，配置将在下次启动时生效';
    }
    return [
        'ok' => true,
        'message' => implode('；', array_filter($notes)) ?: '配置已保存',
        'backup' => $backup,
        'content' => $content,
    ];
}

function env_size_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return $value === '-1' ? -1 : 0;
    }
    if (!preg_match('/^([0-9.]+)\s*([kmg])?b?$/i', $value, $m)) {
        return 0;
    }
    $number = (float)$m[1];
    $unit = strtolower($m[2] ?? '');
    $factor = ['k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3][$unit] ?? 1;
    return (int)round($number * $factor);
}

/* =========================== PHP 扩展 =========================== */

function php_ext_dir(string $key): ?string
{
    $dir = runtime_root($key) . '/ext';
    return is_dir($dir) ? $dir : null;
}

/**
 * 扫描扩展目录 + 读取 ini 中已启用的扩展
 */
function php_ext_items(string $key): array
{
    $dir = php_ext_dir($key);
    $text = php_ini_read($key);
    $enabled = [];
    foreach (ini_get_values($text, 'extension') as $name) {
        $enabled[php_ext_normalize_name($name)] = 'extension';
    }
    foreach (ini_get_values($text, 'zend_extension') as $name) {
        $enabled[php_ext_normalize_name($name)] = 'zend_extension';
    }

    $items = [];
    if ($dir !== null) {
        $files = array_merge(glob($dir . '/php_*.dll') ?: [], glob($dir . '/*.so') ?: []);
        foreach ($files as $file) {
            $name = php_ext_normalize_name(basename($file));
            $items[$name] = [
                'name' => $name,
                'file' => basename($file),
                'size' => (int)@filesize($file),
                'enabled' => isset($enabled[$name]),
                'missing' => false,
                'zend' => $name === 'opcache' || str_starts_with($name, 'xdebug'),
                'recommended' => in_array($name, ['curl', 'fileinfo', 'gd', 'mbstring', 'openssl', 'pdo_mysql', 'mysqli', 'zip', 'exif', 'opcache', 'intl', 'bcmath', 'sodium'], true),
            ];
        }
    }
    foreach ($enabled as $name => $kind) {
        if (!isset($items[$name])) {
            $items[$name] = [
                'name' => $name,
                'file' => '',
                'size' => 0,
                'enabled' => true,
                'missing' => true,
                'zend' => $kind === 'zend_extension',
                'recommended' => false,
            ];
        }
    }
    ksort($items);
    return array_values($items);
}

function php_ext_normalize_name(string $name): string
{
    $name = strtolower(trim($name, " \t\"'"));
    $name = str_replace('\\', '/', $name);
    $name = basename($name);
    $name = preg_replace('/^php_/', '', $name) ?? $name;
    $name = preg_replace('/\.(dll|so)$/', '', $name) ?? $name;
    return $name;
}

/**
 * 批量启用/禁用扩展：一次写入、一次重启
 */
function php_ext_apply(string $key, array $wantEnabled): array
{
    $path = php_ini_path($key);
    if ($path === null) {
        return ['ok' => false, 'message' => '未找到该 PHP 版本的 php.ini 或尚未安装'];
    }
    $items = [];
    foreach (php_ext_items($key) as $item) {
        $items[$item['name']] = $item;
    }
    $want = [];
    foreach ($wantEnabled as $name) {
        $n = php_ext_normalize_name((string)$name);
        if (isset($items[$n]) && !$items[$n]['missing']) {
            $want[$n] = $items[$n];
        }
    }

    $content = php_ini_read($key);
    $touched = [];
    foreach ($items as $name => $item) {
        if ($item['missing']) {
            // 文件不存在的历史条目：清掉，避免启动告警
            if (!$item['enabled']) {
                continue;
            }
            foreach (['extension', 'zend_extension'] as $directive) {
                foreach (ini_get_values($content, $directive) as $existing) {
                    if (php_ext_normalize_name($existing) === $name) {
                        $content = ini_remove_value_line($content, $directive, $existing);
                    }
                }
            }
            $touched[] = $name . '（文件缺失，已移除引用）';
            continue;
        }
        $shouldEnable = isset($want[$name]);
        if ($shouldEnable === $item['enabled']) {
            continue;
        }
        if ($shouldEnable) {
            $directive = $item['zend'] ? 'zend_extension' : 'extension';
            $content = ini_add_line($content, $directive . ' = ' . $name);
            $touched[] = '启用 ' . $name;
        } else {
            foreach (['extension', 'zend_extension'] as $directive) {
                foreach (ini_get_values($content, $directive) as $existing) {
                    if (php_ext_normalize_name($existing) === $name) {
                        $content = ini_remove_value_line($content, $directive, $existing);
                    }
                }
            }
            $touched[] = '禁用 ' . $name;
        }
    }

    if ($touched === []) {
        return ['ok' => true, 'message' => '扩展状态未变化'];
    }
    $result = php_ini_commit($key, $content, 'php-ext', true, $touched);
    if ($result['ok']) {
        log_action('调整 PHP 扩展', runtime_package($key)['name'] . '：' . implode('，', $touched), 'warning');
    }
    return $result;
}

function ini_remove_value_line(string $text, string $directive, string $value): string
{
    $lines = preg_split('/\r?\n/', $text) ?: [];
    $out = [];
    $removed = false;
    foreach ($lines as $line) {
        if (!$removed && preg_match('/^\s*' . preg_quote($directive, '/') . '\s*=\s*(.*)$/i', $line, $m)
            && ini_unquote($m[1]) === $value) {
            $removed = true;
            continue;
        }
        $out[] = $line;
    }
    return rtrim(implode("\n", $out), "\n") . "\n";
}

function ini_add_line(string $text, string $line): string
{
    $lines = preg_split('/\r?\n/', $text) ?: [];
    $index = ini_global_scope_index($lines);
    array_splice($lines, $index, 0, [$line]);
    return rtrim(implode("\n", $lines), "\n") . "\n";
}

/* ========================== Nginx 全局 ========================== */

/**
 * 当前生效 nginx 版本号（用于判断 http2 指令写法）
 */
function nginx_version(): string
{
    static $version = null;
    if ($version !== null) {
        return $version;
    }
    $version = '';
    $root = managed_nginx_root();
    $bin = $root !== null ? $root . '/nginx.exe' : (function_exists('find_nginx_bin') ? find_nginx_bin() : '');
    if ($bin === '' || !is_file($bin)) {
        return $version;
    }
    $result = run_cmd('"' . $bin . '" -v', 15);
    $output = trim(($result['error'] ?? '') . ' ' . ($result['output'] ?? ''));
    if (preg_match('#nginx/([0-9]+\.[0-9]+\.[0-9]+)#i', $output, $m)) {
        $version = $m[1];
    }
    return $version;
}

/**
 * nginx 1.25.1 起推荐 http2 on;（listen ... http2 已废弃）
 */
function nginx_supports_http2_directive(): bool
{
    $version = nginx_version();
    if ($version === '') {
        return false;
    }
    return version_compare($version, '1.25.1', '>=');
}

function nginx_conf_path(): ?string{
    $root = managed_nginx_root();
    if ($root === null) {
        return null;
    }
    $conf = $root . '/conf/nginx.conf';
    return is_file($conf) ? $conf : null;
}

function nginx_knob_defaults(): array
{
    return [
        'worker_processes' => ['label' => '工作进程数', 'type' => 'int', 'default' => '1', 'hint' => '建议等于 CPU 核心数，auto 亦可'],
        'worker_connections' => ['label' => '单进程连接数', 'type' => 'int', 'default' => '1024', 'hint' => '单进程并发上限，防 CC 时不要低于 1024'],
        'keepalive_timeout' => ['label' => '长连接超时（秒）', 'type' => 'int', 'default' => '65', 'hint' => '静态站点 30~65，反代可调大'],
        'client_max_body_size' => ['label' => '默认上传上限', 'type' => 'size', 'default' => '64M', 'hint' => '站点未单独设置时的全局值'],
        'gzip' => ['label' => '启用 gzip', 'type' => 'bool', 'default' => 'On', 'hint' => '压缩文本类响应，节省带宽'],
        'gzip_comp_level' => ['label' => 'gzip 压缩级别', 'type' => 'int', 'default' => '5', 'hint' => '1~9，越高越耗 CPU'],
        'gzip_min_length' => ['label' => 'gzip 最小长度（字节）', 'type' => 'int', 'default' => '1024', 'hint' => '小于该长度不压缩'],
        'server_tokens' => ['label' => '隐藏版本号', 'type' => 'bool', 'default' => 'Off', 'hint' => 'Off 表示不在响应头/错误页暴露 nginx 版本'],
        'access_log' => ['label' => '全局访问日志', 'type' => 'bool', 'default' => 'On', 'hint' => '关闭可省 IO，站点日志不受影响'],
        'sendfile' => ['label' => 'sendfile 零拷贝', 'type' => 'bool', 'default' => 'On', 'hint' => '静态大文件分发建议开启'],
    ];
}

function nginx_knobs_read(string $conf): array
{
    $knobs = [];
    foreach (nginx_knob_defaults() as $key => $meta) {
        $knobs[$key] = $meta['default'];
    }
    $values = [
        'worker_processes' => '/(?:^|\n)\s*worker_processes\s+([^;]+);/i',
        'worker_connections' => '/worker_connections\s+([0-9]+)/i',
        'keepalive_timeout' => '/keepalive_timeout\s+([0-9]+)/i',
        'client_max_body_size' => '/client_max_body_size\s+([0-9]+[kmg]?)/i',
        'gzip_comp_level' => '/gzip_comp_level\s+([0-9]+)/i',
        'gzip_min_length' => '/gzip_min_length\s+([0-9]+)/i',
    ];
    foreach ($values as $key => $pattern) {
        if (preg_match($pattern, $conf, $m)) {
            $knobs[$key] = trim($m[1]);
        }
    }
    $knobs['gzip'] = preg_match('/(?:^|\n)\s*gzip\s+on;/i', $conf) ? 'On' : 'Off';
    $knobs['sendfile'] = preg_match('/sendfile\s+on;/i', $conf) ? 'On' : 'Off';
    $knobs['server_tokens'] = preg_match('/server_tokens\s+on;/i', $conf) ? 'On' : 'Off';
    $knobs['access_log'] = preg_match('/(?:^|\n)\s*access_log\s+off;/i', $conf) ? 'Off' : 'On';
    return $knobs;
}

function nginx_zones_file(): string
{
    $root = managed_nginx_root() ?? runtime_root('nginx');
    return $root . '/conf/tarsk-zones.conf';
}

function nginx_zones_content(): string
{
    $file = nginx_zones_file();
    return is_file($file) ? (string)@file_get_contents($file) : '';
}

/**
 * 站点限流依赖的共享内存区：按站点不同的限流速率各建一个 zone
 * 未启用限流的站点不会引用它；nginx.conf 始终 include 本文件
 */
function env_ensure_zones(): void
{
    if (managed_nginx_root() === null) {
        return;
    }
    $rates = [];
    if (function_exists('fetch_all')) {
        try {
            foreach (fetch_all('SELECT rate_limit FROM ' . t('sites') . ' WHERE rate_limit > 0') as $row) {
                $rate = (int)$row['rate_limit'];
                if ($rate > 0 && $rate <= 100000) {
                    $rates[$rate] = true;
                }
            }
        } catch (Throwable $e) {
            // 表未就绪时忽略，仅生成连接数 zone
        }
    }
    ksort($rates);

    $content = "# 由 Tarsk 生成：站点限流共享内存区，删除会导致启用限流的站点启动失败\n";
    foreach (array_keys($rates) as $rate) {
        $content .= 'limit_req_zone $binary_remote_addr zone=tarsk_req_' . $rate . ':10m rate=' . $rate . "r/s;\n";
    }
    $content .= 'limit_conn_zone $binary_remote_addr zone=tarsk_conn:10m;' . "\n";

    $file = nginx_zones_file();
    if (is_file($file) && (string)file_get_contents($file) === $content) {
        env_ensure_nginx_zones_include();
        return;
    }
    @file_put_contents($file, $content);
    env_ensure_nginx_zones_include();
}

/**
 * 老版本安装的 nginx.conf 里没有 zones 的 include：自动补上，否则启用限流的站点会 nginx -t 失败
 */
function env_ensure_nginx_zones_include(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $path = nginx_conf_path();
    if ($path === null) {
        return;
    }
    $conf = (string)@file_get_contents($path);
    if ($conf === '' || strpos($conf, 'tarsk-zones.conf') !== false) {
        return;
    }
    $line = 'include       tarsk-zones.conf;';
    $new = '';
    if (preg_match('/^([ \t]*)include\s+vhosts\/\*\.conf;/mi', $conf, $m, PREG_OFFSET_CAPTURE)) {
        $offset = $m[0][1];
        $indent = $m[1][0];
        $new = substr($conf, 0, $offset) . $indent . $line . "\n" . $m[0][0] . substr($conf, $offset + strlen($m[0][0]));
    } elseif (preg_match('/\n\}\s*$/', $conf)) {
        $new = preg_replace('/\n\}\s*$/', "\n    " . $line . "\n}\n", $conf) ?? '';
    }
    if ($new === '') {
        return;
    }
    env_backup($path, 'nginx-conf');
    if (@file_put_contents($path, $new) === false) {
        return;
    }
    $test = nginx_conf_test();
    if (!$test['ok']) {
        @file_put_contents($path, $conf);
        return;
    }
    log_action('补全 Nginx zones include', $path, 'warning');
}

/**
 * 站点限流 zone 名
 */
function site_rate_zone(int $rate): string
{
    return 'tarsk_req_' . max(1, $rate);
}

function nginx_conf_build(array $knobs): string
{
    $k = static function (string $key) use ($knobs): string {
        $default = (string)nginx_knob_defaults()[$key]['default'];
        $value = (string)($knobs[$key] ?? $default);
        return $value === '' ? $default : $value;
    };
    $on = static function (string $key) use ($k): bool {
        return strtolower($k($key)) === 'on';
    };

    $http = [
        '    include       mime.types;',
        '    default_type  application/octet-stream;',
        '    sendfile      ' . ($on('sendfile') ? 'on' : 'off') . ';',
        '    keepalive_timeout  ' . $k('keepalive_timeout') . ';',
        '    server_tokens ' . ($on('server_tokens') ? 'on' : 'off') . ';',
        '    client_max_body_size ' . strtolower($k('client_max_body_size')) . ';',
    ];
    if ($on('gzip')) {
        $http[] = '    gzip on;';
        $http[] = '    gzip_comp_level ' . $k('gzip_comp_level') . ';';
        $http[] = '    gzip_min_length ' . $k('gzip_min_length') . ';';
        $http[] = '    gzip_vary on;';
        $http[] = '    gzip_types text/plain text/css text/xml application/json application/javascript'
            . ' application/xml application/xml+rss text/javascript image/svg+xml application/vnd.ms-fontobject'
            . ' font/ttf font/otf;';
    } else {
        $http[] = '    gzip off;';
    }
    $http[] = $on('access_log') ? '    access_log    logs/access.log;' : '    access_log    off;';
    $http[] = '';
    $http[] = '    include       tarsk-zones.conf;';
    $http[] = '    include       vhosts/*.conf;';

    return "# 由 Tarsk 生成 · " . date('Y-m-d H:i:s') . "（可在「环境 · Nginx」页面调整，或直接编辑本文件）\n"
        . 'worker_processes  ' . $k('worker_processes') . ";\n"
        . "error_log  logs/error.log warn;\n"
        . "pid        logs/nginx.pid;\n"
        . "events {\n    worker_connections  " . $k('worker_connections') . ";\n}\n"
        . "http {\n" . implode("\n", $http) . "\n}\n";
}

function nginx_conf_test(): array
{
    $root = managed_nginx_root();
    if ($root === null) {
        if (PHP_OS_FAMILY === 'Linux') {
            $bin = function_exists('find_nginx_bin') ? find_nginx_bin() : '';
            if ($bin === '') {
                return ['ok' => false, 'message' => '未找到系统 Nginx，可安装 Nginx 或配置 system.nginx_bin'];
            }
            $mainConf = trim((string)config('system.nginx_main_conf', ''));
            $command = escapeshellarg($bin) . ' -t' . ($mainConf !== '' ? ' -c ' . escapeshellarg($mainConf) : '') . ' 2>&1';
            $result = run_cmd($command, 25);
            $output = trim(($result['error'] ?? '') . "\n" . ($result['output'] ?? ''));
            return ['ok' => $result['code'] === 0, 'message' => $output !== '' ? $output : 'nginx -t 无输出', 'output' => $output];
        }
        return ['ok' => false, 'message' => '未安装托管 Nginx'];
    }
    $bin = $root . '/nginx.exe';
    if (!is_file($bin)) {
        return ['ok' => false, 'message' => '未找到 nginx.exe'];
    }
    $result = run_cmd('cd /d "' . str_replace('/', '\\', $root) . '" && "' . $bin . '" -t -p "' . str_replace('/', '\\', $root) . '"', 25);
    $output = trim(($result['error'] ?? '') . "\n" . ($result['output'] ?? ''));
    $ok = (bool)preg_match('/syntax is ok/i', $output) && (bool)preg_match('/test is successful/i', $output);
    return ['ok' => $ok, 'message' => $output !== '' ? $output : 'nginx -t 无输出', 'output' => $output];
}

function nginx_conf_apply(string $content): array
{
    $path = nginx_conf_path();
    if ($path === null) {
        return ['ok' => false, 'message' => '未安装托管 Nginx，无法写入全局配置'];
    }
    $original = (string)@file_get_contents($path);
    $backup = env_backup($path, 'nginx-conf');
    if (@file_put_contents($path, $content) === false) {
        return ['ok' => false, 'message' => '写入失败：' . $path];
    }
    $test = nginx_conf_test();
    if (!$test['ok']) {
        if ($original !== '') {
            @file_put_contents($path, $original);
        }
        return ['ok' => false, 'message' => '配置未通过 nginx -t，已自动回滚：' . $test['message'], 'output' => $test['output']];
    }
    $reload = runtime_state('nginx')['running'] ? runtime_nginx_reload() : false;
    log_action('修改 Nginx 全局配置', $path . ($reload ? ' · 已重载' : ''), 'warning');
    return [
        'ok' => true,
        'message' => 'Nginx 全局配置已保存' . ($reload ? '并重载生效' : '（Nginx 未运行，启动后生效）'),
        'backup' => $backup,
        'test' => $test['message'],
    ];
}

function nginx_conf_apply_knobs(array $input): array
{
    $knobs = [];
    foreach (nginx_knob_defaults() as $key => $meta) {
        $raw = (string)($input[$key] ?? $meta['default']);
        $norm = env_normalize_knob($meta['type'], $raw);
        if (!$norm['ok']) {
            return ['ok' => false, 'message' => $meta['label'] . '：' . $norm['error']];
        }
        $knobs[$key] = (string)$norm['value'];
    }
    setting_set('nginx_knobs', json_encode($knobs, JSON_UNESCAPED_UNICODE));
    env_ensure_zones();

    $result = nginx_conf_apply(nginx_conf_build($knobs));
    return $result;
}

function nginx_conf_restore(string $backupFile): array
{
    $path = nginx_conf_path();
    if ($path === null) {
        return ['ok' => false, 'message' => '未安装托管 Nginx'];
    }
    $real = realpath($backupFile);
    $base = realpath(env_backup_dir());
    if (!$real || !$base || !str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', $base))) {
        return ['ok' => false, 'message' => '备份文件不合法'];
    }
    $content = (string)@file_get_contents($real);
    $result = nginx_conf_apply($content);
    if ($result['ok']) {
        $result['message'] = '已回滚到 ' . basename($real) . ' 并重载';
        log_action('回滚 Nginx 配置', basename($real), 'warning');
    }
    return $result;
}

/* ============================== MySQL ============================== */

function mysql_env_status(): array
{
    $pdo = mysql_connection();
    if (!$pdo) {
        return ['ok' => false, 'message' => '无法连接 MySQL', 'status' => []];
    }
    try {
        $raw = [];
        foreach ($pdo->query('SHOW GLOBAL STATUS')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $raw[strtolower((string)$row['Variable_name'])] = (string)$row['Value'];
        }
        $vars = mysql_env_variables();
        $uptime = max(1, (int)($raw['uptime'] ?? 1));
        $queries = (int)($raw['queries'] ?? 0);
        $slow = (int)($raw['slow_queries'] ?? 0);
        $conn = (int)($raw['connections'] ?? 0);
        $maxConn = (int)($vars['max_connections'] ?? 0);
        $threads = (int)($raw['threads_connected'] ?? 0);
        $reads = (int)($raw['innodb_buffer_pool_reads'] ?? 0);
        $readRequests = max(1, (int)($raw['innodb_buffer_pool_read_requests'] ?? 1));

        return [
            'ok' => true,
            'message' => '',
            'status' => [
                'version' => (string)$pdo->query('SELECT VERSION()')->fetchColumn(),
                'uptime' => $uptime,
                'uptime_text' => uptime_text($uptime),
                'qps' => round($queries / $uptime, 2),
                'queries' => $queries,
                'threads_connected' => $threads,
                'max_connections' => $maxConn,
                'conn_usage' => $maxConn > 0 ? round($threads / $maxConn * 100, 1) : 0,
                'connections_total' => $conn,
                'slow_queries' => $slow,
                'slow_ratio' => $queries > 0 ? round($slow / $queries * 100, 3) : 0,
                'buffer_pool_hit' => round((1 - $reads / $readRequests) * 100, 2),
                'buffer_pool_size' => (int)($vars['innodb_buffer_pool_size'] ?? 0),
                'aborted_connects' => (int)($raw['aborted_connects'] ?? 0),
                'max_used_connections' => (int)($raw['max_used_connections'] ?? 0),
                'table_locks_waited' => (int)($raw['table_locks_waited'] ?? 0),
                'created_tmp_disk_tables' => (int)($raw['created_tmp_disk_tables'] ?? 0),
                'select_full_join' => (int)($raw['select_full_join'] ?? 0),
                'bytes_received' => (int)($raw['bytes_received'] ?? 0),
                'bytes_sent' => (int)($raw['bytes_sent'] ?? 0),
            ],
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage(), 'status' => []];
    }
}

function mysql_env_variables(): array
{
    $pdo = mysql_connection();
    if (!$pdo) {
        return [];
    }
    $out = [];
    try {
        foreach ($pdo->query('SHOW GLOBAL VARIABLES')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[strtolower((string)$row['Variable_name'])] = (string)$row['Value'];
        }
    } catch (Throwable $e) {
        return $out;
    }
    return $out;
}

function mysql_env_tunable_schema(): array
{
    return [
        'max_connections' => ['label' => '最大连接数', 'type' => 'int', 'hint' => '并发连接上限，调大需同步提高系统句柄数'],
        'innodb_buffer_pool_size' => ['label' => 'InnoDB 缓冲池', 'type' => 'size', 'hint' => '热数据缓存，建议物理内存的 50%~70%；MySQL 5.7+ 支持在线调整（耗时较长）'],
        'wait_timeout' => ['label' => '空闲连接超时（秒）', 'type' => 'int', 'hint' => '过长会堆积连接，过短会让长连接反复重连'],
        'interactive_timeout' => ['label' => '交互连接超时（秒）', 'type' => 'int', 'hint' => '一般为 wait_timeout 的两倍'],
        'max_allowed_packet' => ['label' => '单包上限（字节）', 'type' => 'size', 'hint' => '大字段写入报 Packet too large 时调大'],
        'table_open_cache' => ['label' => '表缓存数量', 'type' => 'int', 'hint' => '表很多时可调大到 2000+'],
        'long_query_time' => ['label' => '慢查询阈值（秒）', 'type' => 'int', 'hint' => '记录执行时间超过该值的 SQL'],
        'slow_query_log' => ['label' => '开启慢查询日志', 'type' => 'bool', 'hint' => '排查性能问题必备'],
        'tmp_table_size' => ['label' => '内存临时表上限', 'type' => 'size', 'hint' => '与 max_heap_table_size 保持一致'],
        'max_heap_table_size' => ['label' => 'MEMORY 引擎上限', 'type' => 'size', 'hint' => '同上'],
    ];
}

/**
 * 修改 MySQL 运行参数（SET GLOBAL，立即生效，重启后失效）
 */
function mysql_env_set_variable(string $name, string $value): array
{
    $schema = mysql_env_tunable_schema();
    if (!isset($schema[$name])) {
        return ['ok' => false, 'message' => '不在可调参数白名单内'];
    }
    $norm = env_normalize_knob($schema[$name]['type'], $value);
    if (!$norm['ok']) {
        return ['ok' => false, 'message' => $schema[$name]['label'] . '：' . $norm['error']];
    }
    $pdo = mysql_connection();
    if (!$pdo) {
        return ['ok' => false, 'message' => '无法连接 MySQL'];
    }
    $sqlValue = $norm['value'];
    if ($schema[$name]['type'] === 'bool') {
        $sqlValue = strtolower($sqlValue) === 'on' ? 'ON' : 'OFF';
    } elseif ($schema[$name]['type'] === 'size' && !ctype_digit($sqlValue)) {
        $sqlValue = (string)env_size_bytes($sqlValue);
    } elseif (!ctype_digit($sqlValue) && !preg_match('/^[0-9]+(\.[0-9]+)?$/', $sqlValue)) {
        return ['ok' => false, 'message' => '取值不合法'];
    }
    try {
        $pdo->exec('SET GLOBAL ' . $name . ' = ' . $sqlValue);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => '设置失败（需要 SUPER / SYSTEM_VARIABLES_ADMIN 权限）：' . $e->getMessage()];
    }
    log_action('调整 MySQL 参数', $name . ' = ' . $sqlValue, 'warning');
    return ['ok' => true, 'message' => $name . ' 已设为 ' . $sqlValue . '（立即生效，重启后恢复）'];
}

function mysql_env_processes(): array
{
    $pdo = mysql_connection();
    if (!$pdo) {
        return [];
    }
    try {
        return $pdo->query('SHOW FULL PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function mysql_env_kill(int $id): array
{
    $pdo = mysql_connection();
    if (!$pdo) {
        return ['ok' => false, 'message' => '无法连接 MySQL'];
    }
    try {
        $pdo->exec('KILL ' . $id);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
    log_action('终止 MySQL 连接', 'thread ' . $id, 'danger');
    return ['ok' => true, 'message' => '已终止连接 ' . $id];
}

/* ============================== Redis ============================== */

function redis_target(): array
{
    $port = runtime_port('redis');
    $host = '127.0.0.1';
    if ($port <= 0) {
        $port = (int)setting('redis_port', '6380');
    }
    return [
        'host' => $host,
        'port' => $port,
        'pass' => (string)setting('redis_pass', ''),
        'managed' => managed_redis_installed(),
        'running' => managed_redis_installed() ? runtime_state('redis')['running'] : false,
    ];
}

/**
 * 最小 RESP 客户端：不依赖 phpredis 扩展
 */
function redis_cmd(array $args, float $timeout = 2.5): array
{
    $target = redis_target();
    $errno = 0;
    $errstr = '';
    // 连接失败是预期路径（Redis 未启动），局部屏蔽告警，避免被全局错误处理器升级成异常
    set_error_handler(static fn() => true);
    $fp = stream_socket_client(
        'tcp://' . $target['host'] . ':' . $target['port'],
        $errno,
        $errstr,
        $timeout
    );
    restore_error_handler();
    if (!$fp) {
        return ['ok' => false, 'error' => '连接 Redis 失败：' . ($errstr ?: '未运行或端口不通'), 'data' => null];
    }
    stream_set_timeout($fp, (int)ceil($timeout));

    $pipeline = '';
    if ($target['pass'] !== '') {
        $pipeline .= redis_resp_encode(['AUTH', $target['pass']]);
    }
    $pipeline .= redis_resp_encode($args);
    fwrite($fp, $pipeline);

    if ($target['pass'] !== '') {
        $auth = redis_resp_read($fp);
        if (is_array($auth) && isset($auth[0]) && $auth[0] === 'ERR') {
            fclose($fp);
            return ['ok' => false, 'error' => 'Redis 认证失败：' . (string)$auth[1], 'data' => null];
        }
    }
    $reply = redis_resp_read($fp);
    fclose($fp);

    if (is_array($reply) && isset($reply[0]) && $reply[0] === 'ERR') {
        return ['ok' => false, 'error' => (string)$reply[1], 'data' => null];
    }
    return ['ok' => true, 'error' => '', 'data' => $reply];
}

function redis_resp_encode(array $args): string
{
    $out = '*' . count($args) . "\r\n";
    foreach ($args as $arg) {
        $arg = (string)$arg;
        $out .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
    }
    return $out;
}

/**
 * 解析一条 RESP 回复；错误回复返回 ['ERR', message]
 */
function redis_resp_read($fp)
{
    $line = fgets($fp);
    if ($line === false) {
        return ['ERR', '连接被关闭或超时'];
    }
    $type = $line[0];
    $payload = substr($line, 1, -2);
    switch ($type) {
        case '+':
            return $payload;
        case '-':
            return ['ERR', $payload];
        case ':':
            return (int)$payload;
        case '$':
            $len = (int)$payload;
            if ($len < 0) {
                return null;
            }
            $data = '';
            while (strlen($data) < $len + 2) {
                $chunk = fread($fp, $len + 2 - strlen($data));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $data .= $chunk;
            }
            return substr($data, 0, $len);
        case '*':
            $count = (int)$payload;
            if ($count < 0) {
                return null;
            }
            $items = [];
            for ($i = 0; $i < $count; $i++) {
                $items[] = redis_resp_read($fp);
            }
            return $items;
    }
    return ['ERR', '无法识别的回复类型'];
}

function redis_info_all(): array
{
    $result = redis_cmd(['INFO']);
    if (!$result['ok']) {
        return ['ok' => false, 'message' => $result['error'], 'sections' => [], 'flat' => []];
    }
    $sections = [];
    $flat = [];
    $current = 'Server';
    foreach (preg_split('/\r?\n/', (string)$result['data']) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if ($line[0] === '#') {
            $current = trim(substr($line, 1));
            continue;
        }
        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $sections[$current][$parts[0]] = $parts[1];
        $flat[$parts[0]] = $parts[1];
    }
    return ['ok' => true, 'message' => '', 'sections' => $sections, 'flat' => $flat];
}

function redis_scan(string $pattern = '*', int $limit = 100): array
{
    $pattern = $pattern === '' ? '*' : $pattern;
    $limit = max(1, min(500, $limit));
    $cursor = '0';
    $keys = [];
    $iterations = 0;
    do {
        $result = redis_cmd(['SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '200']);
        if (!$result['ok']) {
            return ['ok' => false, 'message' => $result['error'], 'keys' => [], 'total' => 0];
        }
        $reply = $result['data'];
        if (!is_array($reply) || count($reply) !== 2) {
            break;
        }
        $cursor = (string)$reply[0];
        $batch = is_array($reply[1]) ? $reply[1] : [];
        foreach ($batch as $key) {
            $keys[] = (string)$key;
            if (count($keys) >= $limit) {
                break 2;
            }
        }
        $iterations++;
    } while ($cursor !== '0' && $iterations < 40);

    $rows = [];
    foreach ($keys as $key) {
        $type = redis_cmd(['TYPE', $key]);
        $ttl = redis_cmd(['TTL', $key]);
        $mem = redis_cmd(['MEMORY', 'USAGE', $key]);
        $rows[] = [
            'key' => $key,
            'type' => $type['ok'] ? (string)$type['data'] : '?',
            'ttl' => $ttl['ok'] ? (int)$ttl['data'] : -1,
            'size' => $mem['ok'] && is_int($mem['data']) ? (int)$mem['data'] : 0,
        ];
    }
    return ['ok' => true, 'message' => '', 'keys' => $rows, 'total' => count($rows)];
}

function redis_delete_keys(array $keys): array
{
    $keys = array_values(array_filter(array_map('strval', $keys), static fn($k) => $k !== ''));
    if ($keys === []) {
        return ['ok' => false, 'message' => '未选择键'];
    }
    if (count($keys) > 200) {
        $keys = array_slice($keys, 0, 200);
    }
    $result = redis_cmd(array_merge(['DEL'], $keys));
    if (!$result['ok']) {
        return ['ok' => false, 'message' => $result['error']];
    }
    log_action('删除 Redis 键', implode(', ', array_slice($keys, 0, 20)) . (count($keys) > 20 ? ' 等 ' . count($keys) . ' 个' : ''), 'warning');
    return ['ok' => true, 'message' => '已删除 ' . (int)$result['data'] . ' 个键'];
}

function redis_flush_db(): array
{
    $result = redis_cmd(['FLUSHDB']);
    if (!$result['ok']) {
        return ['ok' => false, 'message' => $result['error']];
    }
    log_action('清空 Redis 数据库', 'FLUSHDB', 'danger');
    return ['ok' => true, 'message' => '当前 Redis 数据库已清空'];
}
