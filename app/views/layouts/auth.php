<?php
declare(strict_types=1);

$panelName = $panel_name ?? setting('panel_name', config('app.name', 'Tarsk'));
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? '登录') ?> · <?= e($panelName) ?></title>
<link rel="stylesheet" href="<?= url('/assets/app.css') ?>?v=<?= e(config('app.version', '1.0.0')) ?>">
<link rel="stylesheet" href="<?= url('/assets/ui-kit.css') ?>?v=<?= (int)@filemtime(app_root() . '/public/assets/ui-kit.css') ?>">
<link rel="icon" type="image/svg+xml" href="<?= url('/assets/tarsk-avatar.svg') ?>?v=<?= (int)@filemtime(app_root() . '/public/assets/tarsk-avatar.svg') ?>">
</head>
<body class="auth-body">
<main class="auth">
  <section class="auth__brand">
    <div class="auth__glow"></div>
    <div class="auth__brand-inner">
      <div class="auth__logo"><img src="<?= url('/assets/tarsk-avatar.svg') ?>?v=<?= (int)@filemtime(app_root() . '/public/assets/tarsk-avatar.svg') ?>" alt=""></div>
      <h1><?= e($panelName) ?></h1>
      <p>轻量、直接、可编程的服务器运维面板。<br>站点、文件、数据库、容器与监控，一处掌控。</p>
      <ul class="auth__points">
        <li><?= icon('check', 14) ?>真实系统指标采集</li>
        <li><?= icon('check', 14) ?>站点目录与 Nginx 配置联动</li>
        <li><?= icon('check', 14) ?>数据库、计划任务、防火墙统一管理</li>
      </ul>
      <div class="auth__foot">
        <span>PHP <?= e(PHP_VERSION) ?></span>
        <span><?= e(PHP_OS_FAMILY) ?></span>
        <span><?= e(config('database.name')) ?></span>
      </div>
    </div>
  </section>

  <section class="auth__panel">
    <div class="auth__form-wrap">
      <h2>欢迎回来</h2>
      <p class="auth__sub">请输入管理员账号与密码继续</p>

      <?php foreach (flash_messages() as $flash): ?>
        <div class="alert alert--<?= e($flash['type']) ?>" data-dismissible>
          <?= icon('alert', 16) ?>
          <span><?= e($flash['message']) ?></span>
        </div>
      <?php endforeach; ?>

      <?php if (!empty($notice)): ?>
        <div class="alert alert--info"><?= icon('zap', 16) ?><span><?= e($notice) ?></span></div>
      <?php endif; ?>

      <form method="post" action="<?= url('/login') ?>" class="stack">
        <?= csrf_field() ?>
        <div class="field">
          <label for="username">账号</label>
          <div class="input-wrap">
            <?= icon('user', 16) ?>
            <input id="username" type="text" name="username" placeholder="admin" required autofocus autocomplete="username">
          </div>
        </div>
        <div class="field">
          <label for="password">密码</label>
          <div class="input-wrap">
            <?= icon('lock', 16) ?>
            <input id="password" type="password" name="password" placeholder="••••••••" required autocomplete="current-password">
          </div>
        </div>
        <button class="btn btn--primary btn--block" type="submit">登录面板</button>
      </form>

      <p class="auth__hint">登录行为会记录在操作日志中。忘记密码可执行 <code>php bin/reset-password.php</code>。</p>
    </div>
  </section>
</main>
<script src="<?= url('/assets/app.js') ?>?v=<?= e(config('app.version', '1.0.0')) ?>"></script>
</body>
</html>
