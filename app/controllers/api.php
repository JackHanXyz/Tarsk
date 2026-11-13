<?php
declare(strict_types=1);

/**
 * API Token 管理页（/api）
 */

/* --------------------------- 管理页 --------------------------- */

function api_page(): void
{
    $created = $_SESSION['api_new_token'] ?? '';
    unset($_SESSION['api_new_token']);
    render('api', [
        'title' => 'API Token',
        'active' => 'api',
        'tokens' => api_token_list(),
        'scopes' => api_scope_labels(),
        'created' => $created,
        'base_url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
    ], 'app');
}

function api_create_action(): void
{
    $name = (string)($_POST['name'] ?? '');
    $scope = (string)($_POST['scope'] ?? 'read');
    $days = (int)($_POST['days'] ?? 0);
    $expires = $days > 0 ? date('Y-m-d H:i:s', time() + min(3650, $days) * 86400) : null;
    $result = api_token_create($name, $scope, $expires);
    if ($result['ok']) {
        $_SESSION['api_new_token'] = $result['token'];
        flash('success', $result['message']);
    } else {
        flash('error', $result['message']);
    }
    redirect('/api');
}

function api_delete_action(): void
{
    $result = api_token_delete((int)($_POST['id'] ?? 0));
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/api');
}
