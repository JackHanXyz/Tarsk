<?php
declare(strict_types=1);

$panelName = setting('panel_name', config('app.name', 'Tarsk'));
$currentUser = current_user();
$active = $active ?? '';
$title = $title ?? '面板';
$navGroups = [
    '总览' => [
        ['key' => 'dashboard', 'label' => '概览', 'url' => '/', 'icon' => 'grid'],
    ],
    '运维' => [
        ['key' => 'sites', 'label' => '网站', 'url' => '/sites', 'icon' => 'globe'],
        ['key' => 'deploy', 'label' => '一键部署', 'url' => '/deploy', 'icon' => 'zap'],
        ['key' => 'files', 'label' => '文件', 'url' => '/files', 'icon' => 'folder'],
        ['key' => 'recycle', 'label' => '回收站', 'url' => '/recycle', 'icon' => 'trash'],
        ['key' => 'databases', 'label' => '数据库', 'url' => '/databases', 'icon' => 'database'],
        ['key' => 'monitor', 'label' => '监控', 'url' => '/monitor', 'icon' => 'activity'],
        ['key' => 'cron', 'label' => '计划任务', 'url' => '/cron', 'icon' => 'clock'],
        ['key' => 'firewall', 'label' => '防火墙', 'url' => '/firewall', 'icon' => 'shield'],
        ['key' => 'docker', 'label' => 'Docker', 'url' => '/docker', 'icon' => 'box'],
        ['key' => 'ssl', 'label' => 'SSL 证书', 'url' => '/ssl', 'icon' => 'lock'],
        ['key' => 'ftp', 'label' => 'FTP', 'url' => '/ftp', 'icon' => 'download'],
        ['key' => 'store', 'label' => '软件商店', 'url' => '/store', 'icon' => 'bag'],
    ],
    '系统' => [
        ['key' => 'terminal', 'label' => 'Web 终端', 'url' => '/terminal', 'icon' => 'terminal'],
        ['key' => 'processes', 'label' => '进程管理', 'url' => '/processes', 'icon' => 'cpu'],
        ['key' => 'plugins', 'label' => '插件市场', 'url' => '/plugins', 'icon' => 'box'],
        ['key' => 'logs', 'label' => '操作日志', 'url' => '/logs', 'icon' => 'list'],
        ['key' => 'settings', 'label' => '面板设置', 'url' => '/settings', 'icon' => 'settings'],
    ],
];

// 已启用插件追加到导航
$pluginNav = plugin_nav_items();
if ($pluginNav) {
    $navGroups['插件'] = array_map(function ($item) {
        return ['key' => 'plugin:' . $item['href'], 'label' => $item['title'], 'url' => $item['href'], 'icon' => $item['icon']];
    }, $pluginNav);
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($panelName) ?></title>
<link rel="stylesheet" href="<?= url('/assets/app.css') ?>?v=<?= e(config('app.version', '1.0.0')) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='22' fill='%232563eb'/><text x='50' y='70' font-size='58' font-family='Arial' font-weight='bold' fill='white' text-anchor='middle'>T</text></svg>">
</head>
<body>
<div class="app" id="app">
  <aside class="sidebar">
    <a class="brand" href="<?= url('/') ?>">
      <span class="brand__mark">T</span>
      <span class="brand__text">
        <strong><?= e($panelName) ?></strong>
        <small>Server Panel v<?= e(config('app.version', '1.0.0')) ?></small>
      </span>
    </a>

    <nav class="nav">
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
          <small>管理员</small>
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
<script src="<?= url('/assets/app.js') ?>?v=<?= e(config('app.version', '1.0.0')) ?>"></script>
</body>
</html>
