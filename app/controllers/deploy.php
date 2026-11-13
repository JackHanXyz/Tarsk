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
            'desc' => '全球最流行的博客与 CMS。自动下载、解压、建库并写入 wp-config.php（含随机安全盐）。',
            'needs_db' => true,
            'tips' => '解压后打开站点即可进入安装向导。',
        ],
        'typecho' => [
            'name' => 'Typecho',
            'version' => 'v1.3.0',
            'url' => 'https://github.com/typecho/typecho/releases/download/v1.3.0/typecho.zip',
            'icon' => 'edit',
            'desc' => '轻量级博客程序，单文件安装、占用小。适合小站与个人博客。',
            'needs_db' => true,
            'tips' => '安装向导会自行生成 config.inc.php；确认 usr/ 目录可写。',
        ],
        'discuz' => [
            'name' => 'Discuz! X',
            'version' => 'master（Gitee 源码）',
            'url' => 'https://gitee.com/Discuz/DiscuzX/repository/archive/refs/heads/master.zip',
            'source_sub' => 'upload',
            'icon' => 'user',
            'desc' => '老牌论坛程序，适合社区与站群。源码包会只部署 upload/ 目录内容。',
            'needs_db' => true,
            'tips' => '安装向导会写入 config/ 下的配置；建议部署完成后在站点设置里把默认文档改为 index.php。',
        ],
        'nextcloud' => [
            'name' => 'Nextcloud',
            'version' => 'latest',
            'url' => 'https://download.nextcloud.com/server/releases/latest.zip',
            'icon' => 'box',
            'desc' => '自建网盘与协作平台。安装包较大（约 200 MB），需要较多 PHP 扩展与内存。',
            'needs_db' => true,
            'tips' => '需要 PHP 扩展：gd、zip、curl、mbstring、xml、intl、pdo_mysql；建议 memory_limit ≥ 512M。安装向导会创建 data/ 与 config/。',
        ],
    ];
}

function deploy_page(): void
{
    require_login();
    $sites = fetch_all('SELECT * FROM ' . t('sites') . ' WHERE 1=1' . site_scope_sql() . ' ORDER BY id DESC');
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
    // 不同应用包体积差异很大（Typecho 约 0.6 MB，Nextcloud 约 200 MB），
    // 只做"明显不完整"的下限校验，避免小包被误判为下载失败
    $minSize = (int)($app['min_size'] ?? 20000);
    if (!is_file($zip) || filesize($zip) < $minSize) {
        $actual = is_file($zip) ? filesize($zip) . ' 字节' : '文件不存在';
        @unlink($zip);
        flash('error', '应用包下载失败（' . $actual . '），请检查网络或代理后重试');
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
    $sourceSub = (string)($app['source_sub'] ?? '');
    if ($sourceSub !== '') {
        $source = $extractDir . '/' . trim($sourceSub, '/');
        // 有些包会多一层顶层目录，兜底再往下找一层
        if (!is_dir($source)) {
            $subs = array_values(array_diff(scandir($extractDir) ?: [], ['.', '..']));
            if (count($subs) === 1 && is_dir($extractDir . '/' . $subs[0])) {
                $source = $extractDir . '/' . $subs[0] . '/' . trim($sourceSub, '/');
            }
        }
    } else {
        $sub = array_values(array_diff(scandir($extractDir) ?: [], ['.', '..']));
        $source = count($sub) === 1 && is_dir($extractDir . '/' . $sub[0]) ? $extractDir . '/' . $sub[0] : $extractDir;
    }
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
    $dbFailed = false;
    if ((string)($_POST['create_db'] ?? '') === '1') {
        $dbName = preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$site['name']);
        if (strlen($dbName) > 48) {
            $dbName = substr($dbName, 0, 48);
        }
        $result = mysql_create_database($dbName, 'utf8mb4');
        $messages[] = $result['message'];
        if (!$result['ok']) {
            $dbName = '';
            $dbFailed = true;
        }
    }
    [$dbUser, $dbPass] = mysql_admin_credentials();

    // 5. 应用专属配置
    if ($appKey === 'wordpress') {
        $messages[] = deploy_configure_wordpress($sitePath, $dbName, $dbUser, $dbPass);
    }
    $messages = array_merge($messages, deploy_post_setup($appKey, $sitePath, $dbName));
    if (!empty($app['tips'])) {
        $messages[] = $app['tips'];
    }

    log_action('一键部署', $app['name'] . ' → ' . $site['name'], 'info');
    $url = 'http://' . ($site['domain'] !== '' ? $site['domain'] : '127.0.0.1');
    $port = site_effective_port($site);
    if (!in_array($port, [80], true)) {
        $url .= ':' . $port;
    }
    $summary = $app['name'] . ' 已部署到站点「' . $site['name'] . '」。访问 ' . $url . ' 完成安装向导。' . implode('；', array_filter($messages));
    flash($dbFailed ? 'warning' : 'success', $summary);
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

/**
 * 各应用部署后的目录准备（只做确定性强的事：建可写目录、提示手动步骤）
 */
function deploy_post_setup(string $appKey, string $sitePath, string $dbName): array
{
    $messages = [];
    switch ($appKey) {
        case 'typecho':
            foreach (['usr', 'usr/themes', 'usr/plugins', 'usr/uploads'] as $dir) {
                if (!is_dir($sitePath . '/' . $dir)) {
                    @mkdir($sitePath . '/' . $dir, 0777, true);
                }
            }
            $messages[] = '已准备 usr/ 可写目录；数据库请在安装向导里填写' . ($dbName !== '' ? '（可用 ' . $dbName . '）' : '');
            break;

        case 'discuz':
            foreach (['config', 'data', 'data/attachment', 'data/cache', 'uc_client/data', 'uc_server/data'] as $dir) {
                if (!is_dir($sitePath . '/' . $dir)) {
                    @mkdir($sitePath . '/' . $dir, 0777, true);
                }
            }
            $messages[] = '已创建 config/ 与 data/ 可写目录；如站点仍未生效，请在站点设置把默认文档改成 index.php';
            break;

        case 'nextcloud':
            foreach (['data', 'config', 'apps', 'assets'] as $dir) {
                if (!is_dir($sitePath . '/' . $dir)) {
                    @mkdir($sitePath . '/' . $dir, 0777, true);
                }
            }
            $messages[] = '已创建 data/ 与 config/ 目录；首次访问会进入安装向导' . ($dbName !== '' ? '（数据库可用 ' . $dbName . '）' : '');
            break;

        case 'wordpress':
            $messages[] = '数据库连接已写入 wp-config.php';
            break;
    }
    return $messages;
}
