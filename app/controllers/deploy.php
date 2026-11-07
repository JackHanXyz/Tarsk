<?php
declare(strict_types=1);

/**
 * 一键部署：自动下载、解压、建库并配置常见应用
 */

function deploy_catalog(): array
{
    return [
        'wordpress' => [
            'name' => 'WordPress',
            'version' => '最新稳定版',
            'url' => 'https://wordpress.org/latest.zip',
            'icon' => 'globe',
            'desc' => '全球最流行的博客与 CMS 建站程序。自动完成下载、解压、建库与 wp-config 配置。',
        ],
    ];
}

function deploy_page(): void
{
    require_login();
    $sites = fetch_all('SELECT * FROM ' . t('sites') . ' ORDER BY id DESC');
    render('deploy', [
        'title' => '一键部署',
        'active' => 'deploy',
        'sites' => $sites,
        'catalog' => deploy_catalog(),
    ], 'app');
}

function deploy_run(): void
{
    require_login();
    @set_time_limit(0);

    $appKey = (string)($_POST['app_key'] ?? '');
    $catalog = deploy_catalog();
    $app = $catalog[$appKey] ?? null;
    if (!$app) {
        flash('error', '未知的应用');
        redirect('/deploy');
    }

    $siteId = (int)($_POST['site_id'] ?? 0);
    $site = $siteId > 0 ? fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$siteId]) : null;
    if (!$site) {
        flash('error', '请选择要部署到的站点');
        redirect('/deploy');
    }
    $sitePath = rtrim(str_replace('\\', '/', (string)$site['path']), '/');
    if (!is_dir($sitePath) && !@mkdir($sitePath, 0777, true)) {
        flash('error', '站点目录不存在且无法创建：' . $sitePath);
        redirect('/deploy');
    }

    // 目录非空校验：仅允许面板生成的欢迎页存在，否则需要勾选强制覆盖
    $force = (string)($_POST['force'] ?? '') === '1';
    $entries = array_values(array_diff(scandir($sitePath) ?: [], ['.', '..']));
    $onlyWelcome = $entries === [] || $entries === ['index.html'];
    if (!$onlyWelcome && !$force) {
        flash('error', '站点目录非空。如需覆盖请勾选「强制覆盖」（旧文件会被清理）');
        redirect('/deploy');
    }

    // 1. 下载
    $zip = storage_path('tmp/deploy_' . $appKey . '_' . date('YmdHis') . '.zip');
    $downloadScript = "\$ProgressPreference='SilentlyContinue'\n"
        . "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12\n"
        . "Invoke-WebRequest -Uri '" . $app['url'] . "' -OutFile '" . str_replace("'", "''", $zip) . "' -UseBasicParsing\n";
    ps_run($downloadScript, 900);
    if (!is_file($zip) || filesize($zip) < 1000000) {
        @unlink($zip);
        flash('error', '应用包下载失败，请检查网络后重试');
        redirect('/deploy');
    }

    // 2. 解压
    $extractDir = storage_path('tmp/deploy_' . $appKey . '_' . date('YmdHis'));
    @mkdir($extractDir, 0777, true);
    ps_run(
        "Expand-Archive -Path '" . str_replace("'", "''", $zip) . "' -DestinationPath '"
        . str_replace("'", "''", $extractDir) . "' -Force",
        600
    );
    @unlink($zip);
    $sub = array_values(array_diff(scandir($extractDir) ?: [], ['.', '..']));
    $source = count($sub) === 1 && is_dir($extractDir . '/' . $sub[0]) ? $extractDir . '/' . $sub[0] : $extractDir;
    if (!is_dir($source)) {
        delete_path($extractDir);
        flash('error', '应用包解压失败');
        redirect('/deploy');
    }

    // 3. 部署到站点目录
    if (!$onlyWelcome && $force) {
        delete_path($sitePath);
        @mkdir($sitePath, 0777, true);
    }
    if ($onlyWelcome && is_file($sitePath . '/index.html')) {
        @unlink($sitePath . '/index.html');
    }
    $copied = deploy_copy_dir($source, $sitePath);
    delete_path($extractDir);
    if (!$copied) {
        flash('error', '文件部署失败，请检查目录权限');
        redirect('/deploy');
    }

    $messages = [];

    // 4. 建库
    $dbName = '';
    $dbUser = '';
    $dbPass = '';
    if ((string)($_POST['create_db'] ?? '') === '1') {
        $dbName = preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$site['name']);
        if (strlen($dbName) > 48) {
            $dbName = substr($dbName, 0, 48);
        }
        $result = mysql_create_database($dbName, 'utf8mb4');
        $messages[] = $result['message'];
        if (!$result['ok']) {
            $dbName = '';
        }
    }
    [$dbUser, $dbPass] = mysql_admin_credentials();

    // 5. 应用专属配置
    if ($appKey === 'wordpress') {
        $confMsg = deploy_configure_wordpress($sitePath, $dbName, $dbUser, $dbPass);
        $messages[] = $confMsg;
    }

    log_action('一键部署', $app['name'] . ' → ' . $site['name'], 'info');
    $url = 'http://' . ($site['domain'] !== '' ? $site['domain'] : '127.0.0.1');
    $port = site_effective_port($site);
    if (!in_array($port, [80], true)) {
        $url .= ':' . $port;
    }
    flash('success', $app['name'] . ' 已部署到站点「' . $site['name'] . '」。访问 ' . $url . ' 完成安装向导。' . implode('；', array_filter($messages)));
    redirect('/deploy');
}

function deploy_copy_dir(string $source, string $target): bool
{
    $ok = true;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $dest = $target . '/' . $iterator->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($dest) && !@mkdir($dest, 0777, true)) {
                $ok = false;
            }
        } else {
            if (!is_dir(dirname($dest))) {
                @mkdir(dirname($dest), 0777, true);
            }
            if (!@copy($item->getPathname(), $dest)) {
                $ok = false;
            }
        }
    }
    return $ok;
}

function deploy_configure_wordpress(string $sitePath, string $dbName, string $dbUser, string $dbPass): string
{
    $sample = $sitePath . '/wp-config-sample.php';
    $target = $sitePath . '/wp-config.php';
    if (!is_file($sample)) {
        return '未找到 wp-config-sample.php，请在安装向导中手动填写数据库信息';
    }
    $content = (string)file_get_contents($sample);
    $content = str_replace('database_name_here', $dbName !== '' ? $dbName : 'wordpress', $content);
    $content = str_replace('username_here', $dbUser, $content);
    $content = str_replace('password_here', $dbPass, $content);
    $content = str_replace('localhost', '127.0.0.1', $content);

    // 随机盐
    $salts = ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'];
    foreach ($salts as $salt) {
        $random = bin2hex(random_bytes(32));
        $content = (string)preg_replace(
            "/define\(\s*'" . $salt . "',\s*'[^']*'\s*\);/",
            "define('" . $salt . "', '" . $random . "');",
            $content
        );
    }

    if (@file_put_contents($target, $content) === false) {
        return 'wp-config.php 写入失败，请手动配置';
    }
    return $dbName !== ''
        ? '已写入 wp-config.php（数据库 ' . $dbName . ' / ' . $dbUser . '）'
        : '已写入 wp-config.php（使用面板数据库账号，未单独建库）';
}
