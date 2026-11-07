<?php
declare(strict_types=1);

/**
 * Docker 容器管理
 */
function docker_page(): void
{
    $info = docker_info();

    render('docker', [
        'title' => 'Docker',
        'active' => 'docker',
        'info' => $info,
        'cli' => command_exists('docker'),
    ], 'app');
}

function docker_action(): void
{
    $id = trim((string)($_POST['id'] ?? ''));
    $action = (string)($_POST['action'] ?? '');

    if (!preg_match('/^[a-f0-9]{6,64}$/i', $id)) {
        flash('error', '容器 ID 不合法');
        redirect('/docker');
    }
    $allowed = ['start', 'stop', 'restart', 'rm'];
    if (!in_array($action, $allowed, true)) {
        flash('error', '不支持的操作');
        redirect('/docker');
    }

    $command = 'docker ' . $action . ' ' . ($action === 'rm' ? '-f ' : '') . escapeshellarg($id) . ' 2>&1';
    $res = run_cmd($command, 60);
    $ok = $res['code'] === 0;
    log_action('Docker 操作', $action . ' ' . $id, $ok ? 'info' : 'warning');
    flash($ok ? 'success' : 'error', $ok ? ('容器 ' . $action . ' 成功') : ('操作失败：' . ($res['output'] ?: $res['error'])));
    redirect('/docker');
}

function docker_run(): void
{
    $image = trim((string)($_POST['image'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $ports = trim((string)($_POST['ports'] ?? ''));
    $env = trim((string)($_POST['env'] ?? ''));

    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/:@-]{1,180}$/', $image)) {
        flash('error', '镜像名称不合法');
        redirect('/docker');
    }
    if ($name !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,60}$/', $name)) {
        flash('error', '容器名称不合法');
        redirect('/docker');
    }

    $parts = ['docker', 'run', '-d', '--restart', 'unless-stopped'];
    if ($name !== '') {
        $parts[] = '--name';
        $parts[] = escapeshellarg($name);
    }
    if ($ports !== '') {
        foreach (explode(',', $ports) as $mapping) {
            $mapping = trim($mapping);
            if ($mapping === '') {
                continue;
            }
            if (!preg_match('/^[0-9]{1,5}(:[0-9]{1,5})?(\/(tcp|udp))?$/', $mapping)) {
                flash('error', '端口映射格式不合法，示例：8080:80');
                redirect('/docker');
            }
            $parts[] = '-p';
            $parts[] = escapeshellarg($mapping);
        }
    }
    if ($env !== '') {
        foreach (explode("\n", $env) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=') === false) {
                continue;
            }
            $parts[] = '-e';
            $parts[] = escapeshellarg($line);
        }
    }
    $parts[] = escapeshellarg($image);

    $res = run_cmd(implode(' ', $parts) . ' 2>&1', 240);
    $ok = $res['code'] === 0;
    log_action('启动容器', $image . ' ' . $name, $ok ? 'info' : 'warning');
    flash($ok ? 'success' : 'error', $ok ? ('容器已启动：' . $image) : ('启动失败：' . ($res['output'] ?: $res['error'])));
    redirect('/docker');
}
