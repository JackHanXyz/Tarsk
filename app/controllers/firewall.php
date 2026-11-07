<?php
declare(strict_types=1);

/**
 * 防火墙（Windows / Linux）
 */
function firewall_page(): void
{
    $rules = fetch_all('SELECT * FROM ' . t('firewall') . ' ORDER BY id DESC');
    $status = firewall_status();

    render('firewall', [
        'title' => '防火墙',
        'active' => 'firewall',
        'rules' => $rules,
        'status' => $status,
    ], 'app');
}

function firewall_create(): void
{
    $name = trim((string)($_POST['name'] ?? ''));
    $direction = (string)($_POST['direction'] ?? 'inbound');
    $action = (string)($_POST['action'] ?? 'allow');
    $protocol = (string)($_POST['protocol'] ?? 'tcp');
    $port = trim((string)($_POST['port'] ?? ''));
    $remote = trim((string)($_POST['remote_ip'] ?? 'any'));

    if ($name === '') {
        flash('error', '请填写规则名称');
        redirect('/firewall');
    }
    $direction = in_array($direction, ['inbound', 'outbound'], true) ? $direction : 'inbound';
    $action = in_array($action, ['allow', 'block'], true) ? $action : 'allow';
    $protocol = in_array($protocol, ['tcp', 'udp'], true) ? $protocol : 'tcp';
    if ($port !== '' && !preg_match('/^[0-9, \-]{1,60}$/', $port)) {
        flash('error', '端口格式不合法，示例：80,443 或 8000-9000');
        redirect('/firewall');
    }

    $rule = [
        'name' => $name,
        'direction' => $direction,
        'action' => $action,
        'protocol' => $protocol,
        'port' => $port,
        'remote_ip' => $remote !== '' ? $remote : 'any',
    ];

    $result = firewall_apply($rule);
    execute(
        'INSERT INTO ' . t('firewall') . ' (name, direction, action, protocol, port, remote_ip, status, error, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
        [$name, $direction, $action, $protocol, $port, $rule['remote_ip'], 'enabled', $result['ok'] ? '' : mb_substr($result['message'], 0, 255), now()]
    );
    cache_forget('firewall');

    log_action('新增防火墙规则', $name . ' · ' . $protocol . ' ' . $port, $result['ok'] ? 'info' : 'warning');
    flash($result['ok'] ? 'success' : 'warning', $result['message']);
    redirect('/firewall');
}

function firewall_delete(int $id): void
{
    $rule = fetch_one('SELECT * FROM ' . t('firewall') . ' WHERE id = ?', [$id]);
    if (!$rule) {
        flash('error', '规则不存在');
        redirect('/firewall');
    }
    $result = firewall_remove((string)$rule['name']);
    execute('DELETE FROM ' . t('firewall') . ' WHERE id = ?', [$id]);
    cache_forget('firewall');
    log_action('删除防火墙规则', (string)$rule['name'], 'danger');
    flash($result['ok'] ? 'success' : 'warning', $result['message']);
    redirect('/firewall');
}
