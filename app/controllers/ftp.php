<?php
declare(strict_types=1);

/**
 * FTP 服务：管理内置 FTP 守护进程与 FTP 账号
 */

function ftp_pid_file(): string
{
    return storage_path('tmp/ftpd.pid');
}

function ftp_port(): int
{
    return (int)setting('ftp_port', 2121);
}

function ftp_status(): array
{
    $pid = is_file(ftp_pid_file()) ? (int)trim((string)file_get_contents(ftp_pid_file())) : 0;
    $alive = $pid > 0 && runtime_pid_alive($pid);
    $port = ftp_port();
    $listening = false;
    if ($alive) {
        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        if ($sock) {
            $listening = true;
            fclose($sock);
        }
    }
    return [
        'running' => $alive && $listening,
        'pid' => $pid,
        'port' => $port,
    ];
}

function ftp_page(): void
{
    require_login();
    $users = fetch_all('SELECT * FROM ' . t('ftp_users') . ' ORDER BY id DESC');
    render('ftp', [
        'title' => 'FTP 服务',
        'active' => 'ftp',
        'users' => $users,
        'status' => ftp_status(),
        'sitesRoot' => sites_root(),
    ], 'app');
}

function ftp_user_create(): void
{
    require_login();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $homeDir = trim((string)($_POST['home_dir'] ?? ''));

    if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) {
        flash('error', '用户名须为 3-32 位字母、数字、点、短横线或下划线');
        redirect('/ftp');
    }
    if (strlen($password) < 6) {
        flash('error', '密码至少 6 位');
        redirect('/ftp');
    }
    // 主目录限制在站点根目录内，存储相对路径（FTP 守护进程以站点根为基准拼接）
    $root = str_replace('\\', '/', realpath(sites_root()) ?: sites_root());
    $home = '';
    if ($homeDir !== '') {
        $candidate = str_replace('\\', '/', realpath($root . '/' . ltrim($homeDir, '/')) ?: '');
        if ($candidate === '' || !str_starts_with($candidate, $root)) {
            flash('error', '主目录必须位于站点根目录内');
            redirect('/ftp');
        }
        $home = ltrim(substr($candidate, strlen($root)), '/');
    }
    $exists = fetch_one('SELECT id FROM ' . t('ftp_users') . ' WHERE username = ?', [$username]);
    if ($exists) {
        flash('error', '用户名已存在');
        redirect('/ftp');
    }

    execute(
        'INSERT INTO ' . t('ftp_users') . ' (username, password_hash, home_dir, status, created_at) VALUES (?,?,?,?,?)',
        [$username, password_hash($password, PASSWORD_DEFAULT), $home, 'enabled', now()]
    );
    log_action('创建 FTP 账号', $username, 'warning');
    flash('success', 'FTP 账号已创建：' . $username . '（主目录 ' . $root . ($home !== '' ? '/' . $home : '') . '）');
    redirect('/ftp');
}

function ftp_user_delete(): void
{
    require_login();
    $id = (int)($_POST['id'] ?? 0);
    $row = fetch_one('SELECT * FROM ' . t('ftp_users') . ' WHERE id = ?', [$id]);
    if ($row) {
        execute('DELETE FROM ' . t('ftp_users') . ' WHERE id = ?', [$id]);
        log_action('删除 FTP 账号', $row['username'], 'danger');
        flash('success', '已删除账号 ' . $row['username']);
    }
    redirect('/ftp');
}

function ftp_user_toggle(): void
{
    require_login();
    $id = (int)($_POST['id'] ?? 0);
    $row = fetch_one('SELECT * FROM ' . t('ftp_users') . ' WHERE id = ?', [$id]);
    if ($row) {
        $next = $row['status'] === 'enabled' ? 'disabled' : 'enabled';
        execute('UPDATE ' . t('ftp_users') . ' SET status = ? WHERE id = ?', [$next, $id]);
        log_action($next === 'enabled' ? '启用 FTP 账号' : '禁用 FTP 账号', $row['username'], 'warning');
        flash('success', '已' . ($next === 'enabled' ? '启用' : '禁用') . '账号 ' . $row['username']);
    }
    redirect('/ftp');
}

function ftp_service_action(): void
{
    require_login();
    $action = (string)($_POST['action'] ?? '');
    $status = ftp_status();

    if ($action === 'stop' || $action === 'restart') {
        if ($status['pid'] > 0 && runtime_pid_alive($status['pid'])) {
            run_cmd('taskkill /F /PID ' . $status['pid'], 10);
        }
        @unlink(ftp_pid_file());
        log_action('FTP 服务', '已停止', 'warning');
        if ($action === 'stop') {
            flash('success', 'FTP 服务已停止');
            redirect('/ftp');
        }
        sleep(1);
    }

    if ($action === 'start' || $action === 'restart') {
        if ($status['running'] && $action === 'start') {
            flash('warning', 'FTP 服务已在运行');
            redirect('/ftp');
        }
        $php = PHP_BINARY;
        $script = app_root() . '/../bin/ftpd.php';
        $script = str_replace('\\', '/', realpath(dirname(__DIR__, 2) . '/bin/ftpd.php') ?: $script);
        $cmd = "\$p = Start-Process -FilePath '" . str_replace("'", "''", $php)
            . "' -ArgumentList '\"" . str_replace("'", "''", $script) . "\"'"
            . " -WindowStyle Hidden -PassThru\n"
            . "\$p.Id | Out-File -Encoding ascii '" . str_replace("'", "''", ftp_pid_file()) . "'";
        ps_run($cmd, 15);
        sleep(1);
        $next = ftp_status();
        if ($next['running']) {
            log_action('FTP 服务', '已启动，端口 ' . $next['port'], 'info');
            flash('success', 'FTP 服务已启动，端口 ' . $next['port']);
        } else {
            flash('error', 'FTP 服务启动失败，请查看日志：' . storage_path('logs/ftpd.log'));
        }
    }
    redirect('/ftp');
}
