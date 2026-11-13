<?php
declare(strict_types=1);

/**
 * Git 仓库部署 + Webhook 自动拉取。
 *
 * - 站点可绑定一个 Git 仓库（地址 + 分支 + 随机 secret）；
 * - 手动拉取：首次 = clone（目录需为空），已有仓库 = fetch + reset 到远端分支；
 * - Webhook：POST /webhook/<secret>，兼容 GitHub（X-Hub-Signature-256 HMAC 校验）
 *   与 Gitee/GitLab（payload 里 ref 匹配即拉取），响应为 JSON。
 */

function git_available(): bool
{
    return command_exists('git') !== null;
}

function webhook_secret(): string
{
    return bin2hex(random_bytes(16));
}

function webhook_for_site(int $siteId): ?array
{
    return fetch_one('SELECT * FROM ' . t('webhooks') . ' WHERE site_id = ?', [$siteId]);
}

function webhook_url(array $row): string
{
    return (isset($_SERVER['HTTP_HOST']) ? ($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://' . $_SERVER['HTTP_HOST'] : panel_base_url_guess()) . '/webhook/' . $row['secret'];
}

function panel_base_url_guess(): string
{
    return 'http://localhost:' . (string)($_SERVER['SERVER_PORT'] ?? '80');
}

/**
 * 执行拉取。$repo 为空时用已配置值；目录已有 .git 则走 pull，否则 clone。
 */
function git_deploy_pull(array $site, array $hook): array
{
    if (!git_available()) {
        return ['ok' => false, 'message' => '系统未安装 git，无法执行部署'];
    }
    $repo = trim((string)($hook['repo'] ?? ''));
    $branch = trim((string)($hook['branch'] ?? 'main')) ?: 'main';
    if ($repo === '') {
        return ['ok' => false, 'message' => '未配置仓库地址'];
    }
    $target = str_replace('\\', '/', (string)$site['path']);
    if (!is_dir($target)) {
        return ['ok' => false, 'message' => '站点目录不存在：' . $target];
    }

    $isRepo = is_dir($target . '/.git');
    if (!$isRepo) {
        $entries = array_diff(scandir($target) ?: [], ['.', '..']);
        if ($entries) {
            return ['ok' => false, 'message' => '站点目录非空且不是 Git 仓库，请先清空目录再首次克隆'];
        }
        $cmd = 'git clone --branch ' . escapeshellarg($branch) . ' --single-branch ' . escapeshellarg($repo) . ' ' . escapeshellarg($target);
        $res = run_cmd($cmd, 300);
        if ($res['code'] !== 0) {
            $msg = '克隆失败：' . mb_substr(trim($res['error'] ?: $res['output']), 0, 200);
            webhook_record((int)$hook['id'], $msg, false);
            return ['ok' => false, 'message' => $msg];
        }
        $msg = '首次克隆完成（分支 ' . $branch . '）';
        webhook_record((int)$hook['id'], $msg, true);
        return ['ok' => true, 'message' => $msg];
    }

    $run = static function (string $c) use ($target): array {
        return run_cmd('git -C ' . escapeshellarg($target) . ' ' . $c, 120);
    };
    // 远端地址跟随配置变化
    $run('remote set-url origin ' . escapeshellarg($repo));
    $fetch = $run('fetch origin ' . escapeshellarg($branch));
    if ($fetch['code'] !== 0) {
        $msg = 'fetch 失败：' . mb_substr(trim($fetch['error'] ?: $fetch['output']), 0, 200);
        webhook_record((int)$hook['id'], $msg, false);
        return ['ok' => false, 'message' => $msg];
    }
    $reset = $run('reset --hard origin/' . escapeshellarg($branch));
    if ($reset['code'] !== 0) {
        $msg = 'reset 失败：' . mb_substr(trim($reset['error'] ?: $reset['output']), 0, 200);
        webhook_record((int)$hook['id'], $msg, false);
        return ['ok' => false, 'message' => $msg];
    }
    $info = $run('log -1 --format=%h %s');
    $msg = '已同步到 ' . $branch . ' · ' . trim((string)$info['output']);
    webhook_record((int)$hook['id'], $msg, true);
    log_action('Git 拉取', $site['name'] . ' · ' . $msg);
    return ['ok' => true, 'message' => $msg];
}

function webhook_record(int $id, string $message, bool $ok): void
{
    execute(
        'UPDATE ' . t('webhooks') . ' SET last_pull_at = ?, last_result = ?, pull_count = pull_count + ' . ($ok ? 1 : 0) . ' WHERE id = ?',
        [now(), mb_substr($message, 0, 250), $id]
    );
}

/**
 * Webhook 入口：免登录，凭 secret 寻址。
 */
function git_webhook_dispatch(string $path): void
{
    header('Content-Type: application/json; charset=utf-8');
    $secret = substr($path, strlen('/webhook/'));
    if (!preg_match('/^[a-f0-9]{32}$/', $secret)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'not found']);
        exit;
    }
    $hook = fetch_one('SELECT h.*, s.name AS site_name, s.path AS site_path FROM ' . t('webhooks') . ' h JOIN ' . t('sites') . ' s ON s.id = h.site_id WHERE h.secret = ?', [$secret]);
    if (!$hook) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'not found']);
        exit;
    }

    // GitHub 风格签名（可选）：提供 X-Hub-Signature-256 时必须校验通过
    $sig = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
    $body = (string)file_get_contents('php://input');
    if ($sig !== '') {
        $expect = 'sha256=' . hash_hmac('sha256', $body, (string)$hook['secret']);
        if (!hash_equals($expect, $sig)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => '签名校验失败']);
            exit;
        }
    }

    // ref 过滤：payload 带 ref 时只在分支匹配时拉取
    $payload = json_decode($body, true);
    if (is_array($payload) && isset($payload['ref'])) {
        $ref = 'refs/heads/' . (string)$hook['branch'];
        if ($payload['ref'] !== $ref) {
            echo json_encode(['ok' => true, 'message' => '分支不匹配，跳过（' . $payload['ref'] . '）']);
            exit;
        }
    }

    $site = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [(int)$hook['site_id']]);
    if (!$site) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'message' => '站点已不存在']);
        exit;
    }
    $result = git_deploy_pull($site, $hook);
    log_action('Webhook 拉取', $site['name'] . ' · ' . $result['message'], $result['ok'] ? 'info' : 'warning');
    http_response_code($result['ok'] ? 200 : 500);
    echo json_encode(['ok' => $result['ok'], 'message' => $result['message']]);
    exit;
}
