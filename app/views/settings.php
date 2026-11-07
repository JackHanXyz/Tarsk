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

<section class="grid grid--2 mt-18">
  <article class="card">
    <div class="card__head"><span class="card__title"><?= icon('shield', 17) ?>面板安全</span></div>
    <form method="post" action="<?= url('/settings/security') ?>">
      <?= csrf_field() ?>
      <div class="card__body">
        <div class="form-grid">
          <div class="field span-2">
            <label>安全入口</label>
            <input class="input mono" type="text" name="security_entrance" value="<?= e($settings['security_entrance'] ?? '') ?>" placeholder="留空关闭，例如 tarsk-entry">
            <span class="hint">启用后，仅能通过 <span class="mono">/入口名</span> 访问登录页，其余路径一律 404。请牢记入口名！</span>
          </div>
          <div class="field span-2">
            <label>文件回收站</label>
            <label class="switch">
              <input type="checkbox" name="recycle_enabled" value="1" <?= ($settings['recycle_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
              <span>删除文件时先移入回收站（可还原）</span>
            </label>
          </div>
        </div>
      </div>
      <div class="card__foot" style="display:flex;justify-content:flex-end">
        <button class="btn btn--primary" type="submit"><?= icon('save', 14) ?>保存安全设置</button>
      </div>
    </form>
  </article>

  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('lock', 17) ?>两步验证（TOTP）</span>
      <span class="badge <?= !empty($user['totp_enabled']) ? 'badge--ok' : 'badge--muted' ?>"><i></i><?= !empty($user['totp_enabled']) ? '已开启' : '未开启' ?></span>
    </div>
    <div class="card__body">
      <?php if (empty($user['totp_enabled'])): ?>
        <p class="hint">使用 Google Authenticator、微软 Authenticator 等 App 扫描二维码或手动录入密钥，开启后登录需输入动态验证码。</p>
        <div id="tfaSetup" style="display:none">
          <div class="field">
            <label>密钥（手动录入）</label>
            <input class="input mono" id="tfaSecret" readonly value="">
          </div>
          <div class="field">
            <label>otpauth 链接</label>
            <textarea class="input mono" id="tfaOtpauth" readonly rows="2" style="font-size:11px"></textarea>
          </div>
          <form method="post" action="<?= url('/settings/2fa/enable') ?>">
            <?= csrf_field() ?>
            <div class="field">
              <label>输入 App 中显示的 6 位验证码完成开启</label>
              <input class="input mono" type="text" name="code" pattern="\d{6}" maxlength="6" placeholder="000000" required>
            </div>
            <button class="btn btn--primary btn--sm" type="submit"><?= icon('check', 14) ?>确认开启</button>
          </form>
        </div>
        <button class="btn btn--ghost btn--sm" id="tfaGenBtn" type="button"><?= icon('plus', 14) ?>生成密钥</button>
      <?php else: ?>
        <p class="hint">两步验证已开启。关闭前需在验证器 App 中确认当前动态验证码。</p>
        <form method="post" action="<?= url('/settings/2fa/disable') ?>">
          <?= csrf_field() ?>
          <div class="field">
            <label>当前动态验证码</label>
            <input class="input mono" type="text" name="code" pattern="\d{6}" maxlength="6" placeholder="000000" required>
          </div>
          <button class="btn btn--danger-ghost btn--sm" type="submit" data-confirm="确定关闭两步验证？账户安全性会降低。"><?= icon('x', 14) ?>关闭两步验证</button>
        </form>
      <?php endif; ?>
    </div>
  </article>
</section>

<section class="grid grid--2 mt-18">
  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('lock', 17) ?>面板 SSL</span>
      <span class="badge <?= ($settings['panel_ssl_enabled'] ?? '0') === '1' ? 'badge--ok' : 'badge--muted' ?>"><i></i><?= ($settings['panel_ssl_enabled'] ?? '0') === '1' ? '已开启' : '未开启' ?></span>
    </div>
    <form method="post" action="<?= url('/settings/panel-ssl') ?>">
      <?= csrf_field() ?>
      <div class="card__body">
        <div class="form-grid">
          <div class="field">
            <label>HTTPS 端口</label>
            <input class="input" type="number" name="port" min="1" max="65535" value="<?= e($settings['panel_ssl_port'] ?? '8443') ?>">
          </div>
          <div class="field">
            <label>开关</label>
            <label class="switch">
              <input type="checkbox" name="enabled" value="1" <?= ($settings['panel_ssl_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
              <span>通过托管 Nginx 以 HTTPS 反向代理面板</span>
            </label>
          </div>
        </div>
        <div class="alert alert--info" style="margin:14px 0 0">
          <?= icon('alert', 16) ?>
          <span>需要先在软件商店安装托管 Nginx。使用自签证书，浏览器安全提示属正常现象；面板仍监听原 HTTP 端口，HTTPS 通过 <span class="mono">https://IP:端口</span> 访问。</span>
        </div>
      </div>
      <div class="card__foot" style="display:flex;justify-content:flex-end">
        <button class="btn btn--primary" type="submit"><?= icon('save', 14) ?>应用</button>
      </div>
    </form>
  </article>
</section>

<script>
(function () {
  var btn = document.getElementById('tfaGenBtn');
  if (!btn) return;
  btn.addEventListener('click', function () {
    btn.disabled = true;
    fetch('<?= url('/settings/2fa/prepare') ?>', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: '_token=<?= e(csrf_token()) ?>'
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (data && data.ok) {
        document.getElementById('tfaSecret').value = data.secret;
        document.getElementById('tfaOtpauth').value = data.otpauth;
        document.getElementById('tfaSetup').style.display = '';
        btn.style.display = 'none';
      } else {
        btn.disabled = false;
        alert('生成失败，请重试');
      }
    }).catch(function () { btn.disabled = false; alert('网络错误'); });
  });
})();
</script>
