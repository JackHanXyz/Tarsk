<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (!is_file($root . '/config.php') || !is_file($root . '/install.lock') || $requestPath === '/install') {
    require __DIR__ . '/install.php';
    exit;
}

$GLOBALS['config'] = require $root . '/config.php';
require $root . '/app/bootstrap.php';

$routes = array_merge(require $root . '/app/routes.php', plugin_routes());
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = current_path();

// 面板 IP 白名单：不在名单内的来源直接拒绝
if (!security_ip_allowed(client_ip())) {
    http_response_code(403);
    exit('403 Forbidden：当前 IP 不在面板访问白名单内');
}

// 安全入口：启用后，未登录访问任何路径都需先经过入口路径
$entrance = trim((string)setting('security_entrance', ''));
if ($entrance !== '' && !current_user()) {
    $epath = '/' . trim($entrance, '/');
    if ($path === $epath) {
        $_SESSION['entrance_ok'] = true;
        redirect('/login');
    }
    if (empty($_SESSION['entrance_ok'])) {
        http_response_code(404);
        exit('404 Not Found');
    }
}

// REST API：Bearer Token 认证，不走会话与 CSRF
if (str_starts_with($path, '/api/v1/')) {
    api_dispatch($method, $path);
    exit;
}

// Git Webhook：免登录，凭 secret 寻址
if (str_starts_with($path, '/webhook/')) {
    git_webhook_dispatch($path);
    exit;
}

// 登录页与两步验证页无需完整会话
$guestPaths = ['/login', '/login/2fa'];

// 已启用插件的启动钩子（如 IP 黑名单）
plugin_boot_all();

foreach ($routes as [$routeMethod, $pattern, $file, $function]) {
    if ($routeMethod !== $method) {
        continue;
    }
    if (preg_match('#^' . $pattern . '$#', $path, $matches)) {
        if ($method === 'POST') {
            verify_csrf();
        }
        if (!current_user() && !in_array($path, $guestPaths, true)) {
            if (preg_match('#^/(sites|store)/api(?:/|$)|^/store/install-status$#', $path)) {
                json_response(['ok' => false, 'message' => '请先登录'], 401);
            }
            flash('error', '请先登录');
            redirect('/login');
        }
        // 角色权限：按「方法 + 路径」判定，不通过直接 403
        if (current_user() && !in_array($path, $guestPaths, true)) {
            authz_enforce($method, $path);
        }
        $controller = str_starts_with($file, 'plugin:')
            ? substr($file, 7)
            : $root . '/app/controllers/' . $file;
        require_once $controller;
        array_shift($matches);
        $args = array_map(function ($m) {
            return is_string($m) && ctype_digit($m) ? (int)$m : $m;
        }, $matches);
        $function(...$args);
        exit;
    }
}

http_response_code(404);
if (!current_user()) {
    flash('error', '请先登录');
    redirect('/login');
}
render('404', ['title' => '页面不存在', 'active' => ''], 'app');
