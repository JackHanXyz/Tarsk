<?php
declare(strict_types=1);

$panelName = setting('panel_name', config('app.name', 'Tarsk'));
$currentUser = current_user();
$active = $active ?? '';
$title = $title ?? '面板';
$themePref = (string)setting('theme', 'light');
if (!in_array($themePref, ['light', 'dark', 'auto'], true)) {
    $themePref = 'light';
}
$navGroups = [
    '总览' => [
        ['key' => 'dashboard', 'label' => '概览', 'url' => '/', 'icon' => 'grid'],
    ],
    '运维' => [
        ['key' => 'sites', 'label' => '网站', 'url' => '/sites', 'icon' => 'globe'],
        ['key' => 'deploy', 'label' => '一键部署', 'url' => '/deploy', 'icon' => 'zap'],
        ['key' => 'files', 'label' => '文件', 'url' => '/files', 'icon' => 'folder'],
        ['key' => 'files_scan', 'label' => '文件安全', 'url' => '/files/scan', 'icon' => 'shield'],
        ['key' => 'recycle', 'label' => '回收站', 'url' => '/recycle', 'icon' => 'trash'],
        ['key' => 'databases', 'label' => '数据库', 'url' => '/databases', 'icon' => 'database'],
        ['key' => 'monitor', 'label' => '监控', 'url' => '/monitor', 'icon' => 'activity'],
        ['key' => 'alerts', 'label' => '告警历史', 'url' => '/alerts', 'icon' => 'alert'],
        ['key' => 'backups', 'label' => '备份管理', 'url' => '/backups', 'icon' => 'save'],
        ['key' => 'backups_remote', 'label' => '异地备份', 'url' => '/backups/remote', 'icon' => 'globe'],
        ['key' => 'cron', 'label' => '计划任务', 'url' => '/cron', 'icon' => 'clock'],
        ['key' => 'firewall', 'label' => '防火墙', 'url' => '/firewall', 'icon' => 'shield'],
        ['key' => 'docker', 'label' => 'Docker', 'url' => '/docker', 'icon' => 'box'],
        ['key' => 'ssl', 'label' => 'SSL 证书', 'url' => '/ssl', 'icon' => 'lock'],
        ['key' => 'ftp', 'label' => 'FTP', 'url' => '/ftp', 'icon' => 'download'],
        ['key' => 'store', 'label' => '软件商店', 'url' => '/store', 'icon' => 'bag'],
    ],
    '环境' => [
        ['key' => 'env_php', 'label' => 'PHP 配置', 'url' => '/env/php', 'icon' => 'terminal'],
        ['key' => 'env_nginx', 'label' => 'Nginx 配置', 'url' => '/env/nginx', 'icon' => 'globe'],
        ['key' => 'env_mysql', 'label' => 'MySQL 状态', 'url' => '/env/mysql', 'icon' => 'database'],
        ['key' => 'env_redis', 'label' => 'Redis 管理', 'url' => '/env/redis', 'icon' => 'zap'],
    ],
    '系统' => [
        ['key' => 'users', 'label' => '用户管理', 'url' => '/users', 'icon' => 'user'],
        ['key' => 'system', 'label' => '系统工具', 'url' => '/system', 'icon' => 'cpu'],
        ['key' => 'api', 'label' => 'API Token', 'url' => '/api', 'icon' => 'lock'],
        ['key' => 'projects', 'label' => '应用托管', 'url' => '/projects', 'icon' => 'box'],
        ['key' => 'terminal', 'label' => 'Web 终端', 'url' => '/terminal', 'icon' => 'terminal'],
        ['key' => 'processes', 'label' => '进程管理', 'url' => '/processes', 'icon' => 'cpu'],
        ['key' => 'plugins', 'label' => '插件市场', 'url' => '/plugins', 'icon' => 'box'],
        ['key' => 'update', 'label' => '版本更新', 'url' => '/update', 'icon' => 'refresh'],
        ['key' => 'logs', 'label' => '操作日志', 'url' => '/logs', 'icon' => 'list'],
        ['key' => 'logs_analyze', 'label' => '访问报表', 'url' => '/logs/analyze', 'icon' => 'activity'],
        ['key' => 'settings', 'label' => '面板设置', 'url' => '/settings', 'icon' => 'settings'],
    ],
];

// 按当前角色过滤导航（不显示无权访问的入口）
$roleLabel = authz_roles()[authz_role_of($currentUser)] ?? '只读';
foreach ($navGroups as $group => $items) {
    $filtered = array_values(array_filter($items, static fn($item) => authz_nav_allowed($item['url'])));
    if ($filtered) {
        $navGroups[$group] = $filtered;
    } else {
        unset($navGroups[$group]);
    }
}

// 已启用插件追加到导航
$pluginNav = plugin_nav_items();
if ($pluginNav) {
    $navGroups['插件'] = array_map(function ($item) {
        return ['key' => 'plugin:' . $item['href'], 'label' => $item['title'], 'url' => $item['href'], 'icon' => $item['icon']];
    }, $pluginNav);
}
?>
<!doctype html>
<html lang="zh-CN" data-theme-pref="<?= e($themePref) ?>" data-theme="<?= $themePref === 'dark' ? 'dark' : 'light' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($panelName) ?></title>
<script>
/* 首屏前定主题，避免闪白 */
(function () {
  try {
    var pref = document.documentElement.getAttribute('data-theme-pref') || 'light';
    var saved = localStorage.getItem('tarsk-theme');
    var theme = saved === 'light' || saved === 'dark' ? saved : pref;
    if (theme === 'auto') {
      theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    document.documentElement.setAttribute('data-theme', theme);
  } catch (e) {}
})();
</script>
<link rel="stylesheet" href="<?= url('/assets/app.css') ?>?v=<?= e(config('app.version', '1.0.0')) ?>">
<link rel="stylesheet" href="<?= url('/assets/ui-kit.css') ?>?v=<?= (int)@filemtime(app_root() . '/public/assets/ui-kit.css') ?>">
<link rel="stylesheet" href="<?= url('/assets/theme-dark.css') ?>?v=<?= (int)@filemtime(app_root() . '/public/assets/theme-dark.css') ?>">
<link rel="icon" type="image/svg+xml" href="<?= url('/assets/tarsk-avatar.svg') ?>?v=<?= (int)@filemtime(app_root() . '/public/assets/tarsk-avatar.svg') ?>">
</head>
<body>
<div class="app" id="app">
  <aside class="sidebar">
    <a class="brand" href="<?= url('/') ?>">
      <span class="brand__mark"><img src="<?= url('/assets/tarsk-avatar.svg') ?>?v=<?= (int)@filemtime(app_root() . '/public/assets/tarsk-avatar.svg') ?>" alt=""></span>
      <span class="brand__text">
        <strong><?= e($panelName) ?></strong>
        <small>FIELD CONSOLE · V<?= e(config('app.version', '1.0.0')) ?></small>
      </span>
    </a>

    <div class="nav-search">
      <?= icon('search', 15) ?>
      <input type="search" data-nav-search placeholder="查找工具…" aria-label="搜索导航功能" autocomplete="off">
      <kbd>Ctrl K</kbd>
    </div>

    <nav class="nav" aria-label="主导航">
      <?php foreach ($navGroups as $group => $items): ?>
        <div class="nav__group"><?= e($group) ?></div>
        <?php foreach ($items as $item): ?>
          <a class="nav__item<?= $active === $item['key'] ? ' is-active' : '' ?>" href="<?= url($item['url']) ?>">
            <?= icon($item['icon'], 17) ?>
            <span><?= e($item['label']) ?></span>
            <?php if ($active === $item['key']): ?><i class="nav__indicator"></i><?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar__foot">
      <div class="sidebar__user">
        <span class="avatar avatar--sm"><?= e(mb_substr($currentUser['username'] ?? 'T', 0, 1)) ?></span>
        <div class="sidebar__user-info">
          <strong><?= e($currentUser['username'] ?? '—') ?></strong>
          <small><?= e($roleLabel) ?></small>
        </div>
        <form method="post" action="<?= url('/logout') ?>">
          <?= csrf_field() ?>
          <button class="icon-btn icon-btn--dark" title="退出登录" type="submit"><?= icon('logout', 15) ?></button>
        </form>
      </div>
    </div>
  </aside>

  <main class="main">
    <header class="topbar">
      <div class="topbar__left">
        <button class="icon-btn nav-toggle" type="button" data-nav-toggle title="打开菜单"><?= icon('menu', 16) ?></button>
        <div>
          <div class="topbar__kicker">OPERATIONS / CONTROL ROOM</div>
          <h1><?= e($title) ?></h1>
          <div class="topbar__meta">
            <span><?= date('Y.m.d') ?></span>
            <span class="sep"></span>
            <span id="clock"><?= date('H:i') ?></span>
          </div>
        </div>
      </div>
      <div class="topbar__right">
        <a class="btn btn--ghost btn--sm" href="<?= url('/') ?>"><?= icon('refresh', 14) ?><span>刷新</span></a>
        <button class="icon-btn theme-toggle" type="button" data-theme-toggle title="切换明暗主题" aria-label="切换明暗主题">
          <span class="theme-toggle__icon--sun"><?= icon('sun', 15) ?></span>
          <span class="theme-toggle__icon--moon"><?= icon('moon', 15) ?></span>
        </button>
        <a class="btn btn--ghost btn--sm" href="<?= url('/settings') ?>"><?= icon('settings', 14) ?><span>设置</span></a>
        <span class="chip chip--ok"><i></i>面板运行中</span>
      </div>
    </header>

    <div class="content">
      <?php foreach (flash_messages() as $flash): ?>
        <div class="alert alert--<?= e($flash['type']) ?>" data-dismissible>
          <?= icon($flash['type'] === 'success' ? 'check' : ($flash['type'] === 'error' ? 'alert' : 'alert'), 16) ?>
          <span><?= e($flash['message']) ?></span>
          <button class="alert__close" type="button" data-dismiss><?= icon('x', 14) ?></button>
        </div>
      <?php endforeach; ?>

      <?= $content ?>

      <footer class="foot">
        <span><?= e($panelName) ?> · 轻量服务器运维面板</span>
        <span>PHP <?= e(PHP_VERSION) ?> · <?= e(PHP_OS_FAMILY) ?> · 数据库 <?= e(config('database.name')) ?></span>
      </footer>
    </div>
  </main>
  <div class="app__scrim" data-nav-close></div>
</div>
<div class="toast-stack" id="toastStack"></div>
<script src="<?= url('/assets/app.js') ?>?v=<?= (int)@filemtime(app_root() . '/public/assets/app.js') ?>"></script>
</body>
</html>
