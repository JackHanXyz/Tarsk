<?php
declare(strict_types=1);
?>
<div class="section-head">
  <div>
    <h2>一键部署</h2>
    <p>选择应用与站点，自动完成下载、解压、建库与配置。</p>
  </div>
</div>

<?php if (!$sites): ?>
  <div class="alert alert--warning">
    <?= icon('alert', 16) ?>
    <span>还没有站点。请先在 <a href="<?= url('/sites') ?>">网站管理</a> 中创建站点，再回来部署应用。</span>
  </div>
<?php endif; ?>

<div class="app-grid mt-18">
  <?php foreach ($catalog as $key => $app): ?>
    <article class="card app-card">
      <div class="card__head">
        <span class="card__title"><?= icon($app['icon'], 17) ?><?= e($app['name']) ?></span>
        <span class="badge badge--muted"><?= e($app['version']) ?></span>
      </div>
      <div class="card__body">
        <p class="hint" style="margin:0 0 14px"><?= e($app['desc']) ?></p>
        <form method="post" action="<?= url('/deploy/run') ?>" data-busy>
          <?= csrf_field() ?>
          <input type="hidden" name="app_key" value="<?= e($key) ?>">
          <div class="stack">
            <div class="field">
              <label>部署到站点</label>
              <select class="input" name="site_id" required>
                <option value="">请选择站点</option>
                <?php foreach ($sites as $site): ?>
                  <option value="<?= (int)$site['id'] ?>"><?= e($site['name']) ?>（<?= e($site['domain'] !== '' ? $site['domain'] : '未绑定域名') ?>）</option>
                <?php endforeach; ?>
              </select>
            </div>
            <label class="switch">
              <input type="checkbox" name="create_db" value="1" checked>
              <span>自动创建同名数据库（使用面板 MySQL 管理账号）</span>
            </label>
            <label class="switch">
              <input type="checkbox" name="force" value="1">
              <span>强制覆盖（站点目录非空时清空后部署，慎用）</span>
            </label>
            <button class="btn btn--primary" type="submit" <?= !$sites ? 'disabled' : '' ?>><?= icon('zap', 14) ?>开始部署</button>
          </div>
        </form>
      </div>
      <div class="card__foot"><span class="small muted">下载自官方源：<?= e($app['url']) ?></span></div>
    </article>
  <?php endforeach; ?>
</div>

<div class="card mt-18">
  <div class="card__head"><span class="card__title"><?= icon('alert', 17) ?>部署说明</span></div>
  <div class="card__body">
    <ul class="small" style="margin:0;padding-left:18px;line-height:2">
      <li>部署完成后访问站点地址，按应用安装向导完成最后一步（站点标题、管理员账号等）。</li>
      <li>数据库信息已自动写入应用配置文件（如 wp-config.php），使用面板配置的数据库账号。</li>
      <li>首次部署需下载应用安装包（约 25 MB），请保持网络畅通；部署过程可能需要 1-2 分钟，请勿重复提交。</li>
      <li>部署的站点若使用托管 Nginx，请确保其在软件商店中处于运行状态。</li>
    </ul>
  </div>
</div>
