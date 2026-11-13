<?php
declare(strict_types=1);

/**
 * 应用托管（Node / Python / Java）+ Git 部署 Webhook 配置。
 */

function projects_page(): void
{
    $rows = fetch_all('SELECT * FROM ' . t('project_apps') . ' ORDER BY id');
    foreach ($rows as &$row) {
        $row = project_sync_status($row);
    }
    unset($row);

    // 每个 Git 站点的 webhook 概览
    $hooks = fetch_all(
        'SELECT h.*, s.name AS site_name FROM ' . t('webhooks') . ' h JOIN ' . t('sites') . ' s ON s.id = h.site_id ORDER BY h.id'
    );

    render('projects', [
        'title' => '应用托管',
        'active' => 'projects',
        'projects' => $rows,
        'hooks' => $hooks,
        'binaries' => [
            'node' => project_detect_binary('node'),
            'python' => project_detect_binary('python'),
            'java' => project_detect_binary('java'),
        ],
        'git' => git_available(),
    ], 'app');
}

function projects_save(): void
{
    authz_enforce('POST', '/projects');
    $id = (int)($_POST['id'] ?? 0);
    $input = [
        'id' => $id,
        'name' => trim((string)($_POST['name'] ?? '')),
        'kind' => (string)($_POST['kind'] ?? 'node'),
        'path' => trim((string)($_POST['path'] ?? '')),
        'command' => trim((string)($_POST['command'] ?? '')),
        'port' => (int)($_POST['port'] ?? 0),
    ];
    $check = project_validate($input);
    if (!$check['ok']) {
        flash('error', $check['message']);
        redirect('/projects');
    }
    $autostart = !empty($_POST['autostart']) ? 1 : 0;
    $fields = [$input['name'], $input['kind'], $input['path'], $input['command'], $input['port'], $autostart];

    if ($id > 0) {
        $old = fetch_one('SELECT * FROM ' . t('project_apps') . ' WHERE id = ?', [$id]);
        if (!$old) {
            flash('error', '项目不存在');
            redirect('/projects');
        }
        execute(
            'UPDATE ' . t('project_apps') . ' SET name=?, kind=?, path=?, command=?, port=?, autostart=? WHERE id=?',
            [...$fields, $id]
        );
        $row = fetch_one('SELECT * FROM ' . t('project_apps') . ' WHERE id = ?', [$id]);
        $auto = project_autostart_apply($row);
        log_action('修改应用', $input['name']);
        flash('success', '已保存。' . $auto['message']);
        redirect('/projects');
    }

    execute(
        'INSERT INTO ' . t('project_apps') . ' (name,kind,path,command,port,autostart,created_at) VALUES (?,?,?,?,?,?,?)',
        [...$fields, now()]
    );
    $newId = (int)db()->lastInsertId();
    $row = fetch_one('SELECT * FROM ' . t('project_apps') . ' WHERE id = ?', [$newId]);
    $auto = project_autostart_apply($row);
    log_action('添加应用', $input['name'] . '（' . $input['kind'] . '）');
    flash($auto['ok'] ? 'success' : 'warning', '已添加。' . $auto['message']);
    redirect('/projects');
}

function projects_delete(int $id): void
{
    authz_enforce('POST', '/projects');
    $row = fetch_one('SELECT * FROM ' . t('project_apps') . ' WHERE id = ?', [$id]);
    if (!$row) {
        flash('error', '项目不存在');
        redirect('/projects');
    }
    if ((int)$row['pid'] > 0) {
        project_stop($row);
    }
    if (PHP_OS_FAMILY === 'Windows') {
        run_cmd('schtasks /Delete /TN ' . escapeshellarg('Tarsk_Project_' . $id) . ' /F', 15);
    }
    execute('DELETE FROM ' . t('project_apps') . ' WHERE id = ?', [$id]);
    @unlink(project_log_file($id, 'out'));
    @unlink(project_log_file($id, 'err'));
    log_action('删除应用', $row['name']);
    flash('success', '已删除「' . $row['name'] . '」');
    redirect('/projects');
}

function projects_start(int $id): void
{
    authz_enforce('POST', '/projects');
    $row = fetch_one('SELECT * FROM ' . t('project_apps') . ' WHERE id = ?', [$id]);
    if (!$row) {
        flash('error', '项目不存在');
        redirect('/projects');
    }
    $result = project_start($row);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/projects');
}

function projects_stop(int $id): void
{
    authz_enforce('POST', '/projects');
    $row = fetch_one('SELECT * FROM ' . t('project_apps') . ' WHERE id = ?', [$id]);
    if (!$row) {
        flash('error', '项目不存在');
        redirect('/projects');
    }
    $result = project_stop($row);
    flash($result['ok'] ? 'success' : 'warning', $result['message']);
    redirect('/projects');
}

function projects_logs(int $id): void
{
    $row = fetch_one('SELECT * FROM ' . t('project_apps') . ' WHERE id = ?', [$id]);
    if (!$row) {
        render('404', ['title' => '页面不存在', 'active' => ''], 'app');
        return;
    }
    render('project_logs', [
        'title' => '日志 · ' . $row['name'],
        'active' => 'projects',
        'project' => $row,
        'stdout' => project_tail_log($id, 'out'),
        'stderr' => project_tail_log($id, 'err'),
    ], 'app');
}

/* --------------------------- Git Webhook --------------------------- */

function webhook_save(): void
{
    authz_enforce('POST', '/projects');
    $siteId = (int)($_POST['site_id'] ?? 0);
    $site = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$siteId]);
    if (!$site) {
        flash('error', '站点不存在');
        redirect('/projects');
    }
    $repo = trim((string)($_POST['repo'] ?? ''));
    $branch = trim((string)($_POST['branch'] ?? 'main')) ?: 'main';
    if ($repo !== '' && !preg_match('#^(https?://|git@)#', $repo)) {
        flash('error', '仓库地址需以 https://、http:// 或 git@ 开头');
        redirect('/projects');
    }
    $existing = webhook_for_site($siteId);
    if ($existing) {
        $secret = trim((string)($_POST['secret'] ?? ''));
        if ($secret === '') {
            $secret = (string)$existing['secret'];
        } elseif (!preg_match('/^[a-f0-9]{32}$/', $secret)) {
            flash('error', 'secret 必须是 32 位十六进制');
            redirect('/projects');
        }
        execute(
            'UPDATE ' . t('webhooks') . ' SET repo = ?, branch = ?, secret = ? WHERE id = ?',
            [$repo, $branch, $secret, (int)$existing['id']]
        );
        log_action('修改 Git 部署', $site['name'] . ' · ' . $repo . '#' . $branch);
    } else {
        if ($repo === '') {
            flash('error', '仓库地址不能为空');
            redirect('/projects');
        }
        $secret = webhook_secret();
        execute(
            'INSERT INTO ' . t('webhooks') . ' (site_id, repo, branch, secret, created_at) VALUES (?,?,?,?,?)',
            [$siteId, $repo, $branch, $secret, now()]
        );
        log_action('绑定 Git 部署', $site['name'] . ' · ' . $repo . '#' . $branch);
    }
    flash('success', 'Git 部署配置已保存');
    redirect('/projects');
}

function webhook_pull(int $id): void
{
    authz_enforce('POST', '/projects');
    $hook = fetch_one('SELECT * FROM ' . t('webhooks') . ' WHERE id = ?', [$id]);
    $site = $hook ? fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [(int)$hook['site_id']]) : null;
    if (!$hook || !$site) {
        flash('error', '配置不存在');
        redirect('/projects');
    }
    $result = git_deploy_pull($site, $hook);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/projects');
}

function webhook_delete(int $id): void
{
    authz_enforce('POST', '/projects');
    $hook = fetch_one('SELECT * FROM ' . t('webhooks') . ' WHERE id = ?', [$id]);
    if ($hook) {
        execute('DELETE FROM ' . t('webhooks') . ' WHERE id = ?', [$id]);
        log_action('解绑 Git 部署', '站点 #' . $hook['site_id']);
    }
    flash('success', '已解绑');
    redirect('/projects');
}
