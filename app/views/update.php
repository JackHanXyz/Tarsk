<?php
declare(strict_types=1);
$hasRemote = is_array($remote) && !empty($remote['ok']);
$hasUpdate = $hasRemote && ($local_sha === '' || $local_sha !== (string)($remote['sha'] ?? ''));
?>
<div class="section-head">
  <div>
    <h2>版本更新</h2>
    <p>从 GitHub 仓库拉取最新代码。更新会保留 <code>config.php</code>、<code>storage</code>（站点数据/证书/备份）与 <code>install.lock</code>。</p>
  </div>
  <div class="toolbar">
    <form method="post" action="<?= url('/update/check') ?>" data-busy>
      <?= csrf_field() ?>
      <button class="btn btn--ghost btn--sm" type="submit"><?= icon('refresh', 14) ?>检查更新</button>
    </form>
    <?php if ($hasUpdate): ?>
      <form method="post" action="<?= url('/update/apply') ?>" data-confirm="确定从 GitHub 更新到最新代码？更新后需要重启面板进程。" data-busy>
        <?= csrf_field() ?>
        <button class="btn btn--primary btn--sm" type="submit"><?= icon('download', 14) ?>立即更新</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="grid grid--2">
  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('server', 17) ?>当前版本</span>
      <span class="badge badge--info"><?= e($branch) ?> 分支</span>
    </div>
    <div class="card__body">
      <dl class="kv">
        <dt>版本号</dt><dd class="mono"><?= e($local_version) ?></dd>
        <dt>提交 SHA</dt><dd class="mono small"><?= e($local_sha !== '' ? $local_sha : '未知（未记录版本信息）') ?></dd>
        <dt>仓库</dt><dd><a class="mono small" href="<?= e($repo_url) ?>" target="_blank" rel="noopener"><?= e($repo) ?></a></dd>
        <dt>最近更新</dt><dd class="small"><?= e($state['updated_at'] ?? '—') ?></dd>
        <dt>上次操作</dt><dd class="small"><?= e($last_result ?: '—') ?></dd>
      </dl>
    </div>
  </article>

  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('zap', 17) ?>远端最新</span>
      <?php if ($hasUpdate): ?>
        <span class="badge badge--warn"><i></i>有新版本</span>
      <?php elseif ($hasRemote): ?>
        <span class="badge badge--ok"><i></i>已是最新</span>
      <?php else: ?>
        <span class="badge badge--muted">未检查</span>
      <?php endif; ?>
    </div>
    <div class="card__body">
      <?php if ($hasRemote): ?>
        <dl class="kv">
          <dt>提交 SHA</dt><dd class="mono small"><?= e((string)($remote['sha'] ?? '')) ?></dd>
          <dt>提交说明</dt><dd class="small"><?= e((string)($remote['message'] ?? '')) ?></dd>
          <dt>提交时间</dt><dd class="small"><?= e((string)($remote['date'] ?? '')) ?></dd>
          <dt>检查时间</dt><dd class="small"><?= e((string)($remote['checked_at'] ?? '')) ?></dd>
        </dl>
      <?php else: ?>
        <div class="empty">
          <?= icon('refresh', 30) ?>
          <p>还没有检查过远端版本</p>
          <small>点击右上角「检查更新」，结果会缓存 10 分钟</small>
        </div>
      <?php endif; ?>
    </div>
  </article>
</div>

<?php if (!$hasRemote && $remote !== null && empty($remote['ok'])): ?>
  <div class="alert alert--error mt-18">
    <?= icon('alert', 16) ?>
    <span><?= e((string)($remote['message'] ?? '无法访问 GitHub')) ?></span>
  </div>
<?php endif; ?>

<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('settings', 17) ?>更新通道</span>
  </div>
  <form method="post" action="<?= url('/update/settings') ?>">
    <?= csrf_field() ?>
    <div class="card__body">
      <div class="form-grid">
        <div class="field">
          <label>分支 / 标签</label>
          <input class="input mono" type="text" name="branch" value="<?= e($branch) ?>" placeholder="main">
          <span class="hint">默认拉取 main 分支最新提交</span>
        </div>
        <div class="field">
          <label>下载代理（可选）</label>
          <input class="input mono" type="text" name="proxy" value="<?= e($proxy) ?>" placeholder="http://127.0.0.1:10808">
          <span class="hint">服务器无法直连 GitHub 时填写，例如国内网络环境</span>
        </div>
      </div>
      <div class="alert alert--warning" style="margin:14px 0 0">
        <?= icon('alert', 16) ?>
        <span>更新会覆盖 <code>app</code>、<code>bin</code>、<code>public</code> 等代码目录，并在更新前把当前代码打包到备份目录。更新完成后需重启面板进程（Linux <code>systemctl restart tarsk</code>；Windows 结束计划任务 <code>Tarsk_Panel</code> 后重新运行）。</span>
      </div>
    </div>
    <div class="card__foot" style="display:flex;justify-content:flex-end">
      <button class="btn btn--primary" type="submit"><?= icon('save', 14) ?>保存通道设置</button>
    </div>
  </form>
</section>
