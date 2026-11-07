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

$routes = require $root . '/app/routes.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = current_path();

foreach ($routes as [$routeMethod, $pattern, $file, $function]) {
    if ($routeMethod !== $method) {
        continue;
    }
    if (preg_match('#^' . $pattern . '$#', $path, $matches)) {
        if ($method === 'POST') {
            verify_csrf();
        }
        if (!current_user() && !in_array($path, ['/login'], true)) {
            flash('error', '请先登录');
            redirect('/login');
        }
        require_once $root . '/app/controllers/' . $file;
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
