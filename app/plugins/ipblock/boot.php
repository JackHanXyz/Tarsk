<?php
declare(strict_types=1);

/**
 * 插件启动钩子：每个请求进入路由前执行
 */
function ipblock_boot(): void
{
    $list = trim((string)setting('ipblock_list', ''));
    if ($list === '') {
        return;
    }
    $ip = client_ip();
    foreach (preg_split('/\r?\n/', $list) as $rule) {
        $rule = trim($rule);
        if ($rule === '') {
            continue;
        }
        $pattern = '/^' . str_replace(['.', '*'], ['\.', '\d+'], $rule) . '$/';
        if (preg_match($pattern, $ip)) {
            http_response_code(403);
            exit('403 Forbidden');
        }
    }
}
