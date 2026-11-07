<?php
declare(strict_types=1);
?>
<div class="section-head">
  <div>
    <h2>面板设置</h2>
    <p>调整站点路径、数据库管理账号与面板行为。</p>
  </div>
  <span class="badge badge--info"><?= e($info['version']) ?></span>
</div>

<section class="grid grid--sidebar">
  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('settings', 17) ?>基础设置</span>
    </div>
    <form method="post" action="<?= url('/settings/save') ?>">
      <?= csrf_field() ?>
      <div class="card__body">
        <div class="form-grid">
          <div class="field">
            <label>面板名称</label>
            <input class="input" type="text" name="panel_name" value="<?= e($settings['panel_name'] ?? 'Tarsk') ?>">
          </div>
          <div class="field">
            <label>监控刷新间隔（秒）</label>
            <input class="input" type="number" name="refresh_interval" min="1" max="30" value="<?= e($settings['refresh_interval'] ?? '3') ?>">
          </div>
          <div class="field">
            <label>托管 Nginx 端口</label>
            <input class="input" type="number" name="nginx_port" min="1" max="65535" value="<?= e($settings['nginx_port'] ?? '8080') ?>">
            <span class="hint">修改后需在软件商店重启托管 Nginx</span>
          </div>
          <div class="field">
            <label>托管 PHP 端口</label>
            <input class="input" type="number" name="php_port" min="1" max="65535" value="<?= e($settings['php_port'] ?? '9000') ?>">
            <span class="hint">PHP-CGI FastCGI 监听端口</span>
          </div>
          <div class="field">
            <label>托管 Redis 端口</label>
            <input class="input" type="number" name="redis_port" min="1" max="65535" value="<?= e($settings['redis_port'] ?? '6380') ?>">
            <span class="hint">修改后需重启 Redis</span>
          </div>
          <div class="field">
            <label>站点根目录</label>
            <input class="input mono" type="text" name="sites_root" value="<?= e($settings['sites_root'] ?? '') ?>" placeholder="<?= e($info['sites_root']) ?>">
            <span class="hint">留空自动使用 D:/wwwroot 或 storage/www</span>
          </div>
          <div class="field">
            <label>备份目录</label>
            <input class="input mono" type="text" name="backup_path" value="<?= e($settings['backup_path'] ?? '') ?>" placeholder="<?= e($info['backup_path']) ?>">
            <span class="hint">留空自动使用 D:/backup 或 storage/backup</span>
          </div>
          <div class="field span-2">
            <label>登录页公告</label>
            <input class="input" type="text" name="login_notice" value="<?= e($settings['login_notice'] ?? '') ?>" placeholder="可选，展示在登录页">
          </div>
        </div>
      </div>
      <div class="card__foot" style="display:flex;justify-content:flex-end">
        <button class="btn btn--primary" type="submit"><?= icon('save', 14) ?>保存设置</button>
      </div>
    </form>
  </article>

  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('database', 17) ?>MySQL 管理账号</span>
      <span class="badge <?= $mysql['connected'] ? 'badge--ok' : 'badge--warn' ?>"><i></i><?= $mysql['connected'] ? '已连接' : '未连接' ?></span>
    </div>
    <form method="post" action="<?= url('/settings/save') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="panel_name" value="<?= e($settings['panel_name'] ?? 'Tarsk') ?>">
      <input type="hidden" name="refresh_interval" value="<?= e($settings['refresh_interval'] ?? '3') ?>">
      <input type="hidden" name="sites_root" value="<?= e($settings['sites_root'] ?? '') ?>">
      <input type="hidden" name="backup_path" value="<?= e($settings['backup_path'] ?? '') ?>">
      <input type="hidden" name="login_notice" value="<?= e($settings['login_notice'] ?? '') ?>">
      <div class="card__body">
        <div class="form-grid">
          <div class="field span-2">
            <label>管理账号</label>
            <input class="input" type="text" name="mysql_admin_user" value="<?= e($settings['mysql_admin_user'] ?? '') ?>" placeholder="<?= e(config('database.user')) ?>">
            <span class="hint">留空则使用面板配置中的数据库账号（<?= e(config('database.user')) ?>）。</span>
          </div>
          <div class="field span-2">
            <label>管理密码</label>
            <input class="input" type="password" name="mysql_admin_pass" value="<?= e($settings['mysql_admin_pass'] ?? '') ?>" placeholder="填写后启用完整数据库管理权限">
          </div>
        </div>
        <div class="alert alert--info" style="margin:14px 0 0">
          <?= icon('alert', 16) ?>
          <span>当前账号 <?= e($mysql['user']) ?> 受 MySQL 授权限制，仅能管理可见数据库。填写 root 账号后可创建、删除任意数据库。</span>
        </div>
      </div>
      <div class="card__foot" style="display:flex;justify-content:flex-end">
        <button class="btn btn--primary" type="submit"><?= icon('save', 14) ?>保存账号</button>
      </div>
    </form>
  </article>
</section>

<section class="grid grid--2 mt-18">
  <article class="card">
    <div class="card__head"><span class="card__title"><?= icon('user', 17) ?>账号安全</span></div>
    <form method="post" action="<?= url('/settings/password') ?>">
      <?= csrf_field() ?>
      <div class="card__body">
        <div class="stack">
          <div class="field">
            <label>当前密码</label>
            <input class="input" type="password" name="current_password" required>
          </div>
          <div class="form-grid">
            <div class="field">
              <label>新密码</label>
              <input class="input" type="password" name="new_password" required minlength="8">
            </div>
            <div class="field">
              <label>确认新密码</label>
              <input class="input" type="password" name="confirm_password" required minlength="8">
            </div>
          </div>
          <p class="hint">修改成功后当前会话会立即失效，需要重新登录。</p>
        </div>
      </div>
      <div class="card__foot" style="display:flex;justify-content:flex-end">
        <button class="btn btn--danger-ghost" type="submit"><?= icon('lock', 14) ?>更新密码</button>
      </div>
    </form>
  </article>

  <article class="card">
    <div class="card__head"><span class="card__title"><?= icon('server', 17) ?>系统信息</span></div>
    <div class="card__body">
      <dl class="kv">
        <dt>运行平台</dt><dd><?= e($info['platform']) ?></dd>
        <dt>PHP 版本</dt><dd><?= e($info['php']) ?> · <?= e($info['sapi']) ?></dd>
        <dt>主机名</dt><dd><?= e($info['hostname']) ?></dd>
        <dt>站点根目录</dt><dd class="mono small"><?= e($info['sites_root']) ?></dd>
        <dt>备份目录</dt><dd class="mono small"><?= e($info['backup_path']) ?></dd>
        <dt>Nginx</dt><dd class="mono small"><?= e($info['nginx']) ?></dd>
        <dt>MySQL 程序</dt><dd class="mono small"><?= e($info['mysql_bin']) ?></dd>
        <dt>数据库 / 前缀</dt><dd class="mono small"><?= e($info['db_name']) ?> / <?= e($info['prefix']) ?></dd>
        <dt>系统盘可用</dt><dd><?= bytes($info['disk_free']) ?></dd>
      </dl>
    </div>
  </article>
</section>
