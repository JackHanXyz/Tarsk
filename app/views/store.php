<?php
declare(strict_types=1);

$installedCount = count(array_filter($apps, fn($a) => $a['installed']));
$appIcon = [
    'nginx' => 'globe', 'mysql' => 'database', 'php' => 'terminal', 'redis' => 'zap',
    'docker' => 'box', 'node' => 'server', 'git' => 'copy', 'composer' => 'bag',
    'openssl' => 'lock', 'python' => 'activity',
];
?>
<div class="section-head">
  <div>
    <h2>软件商店</h2>
    <p>探测本机已安装的运行环境，并通过 winget 静默安装缺失组件。</p>
  </div>
  <div class="toolbar">
    <form method="post" action="<?= url('/store/check') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="key" value="all">
      <button class="btn btn--ghost btn--sm" type="submit"><?= icon('refresh', 14) ?>重新检测全部</button>
    </form>
  </div>
</div>

<?php if ($platform !== 'Windows'): ?>
  <div class="alert alert--info"><?= icon('alert', 16) ?><span>当前系统为 <?= e($platform) ?>，一键安装仅支持 Windows；检测数据仍可正常使用。</span></div>
<?php elseif (!$winget): ?>
  <div class="alert alert--warning"><?= icon('alert', 16) ?><span>未检测到 winget，安装按钮将不可用。可在“应用商店”更新「应用安装程序」后重试，或前往各软件官网下载。</span></div>
<?php endif; ?>

<div class="app-grid">
  <?php foreach ($apps as $app): ?>
    <article class="app-card">
      <div class="app-card__head">
        <div class="app-card__id">
          <span class="app-ico"><?= icon($appIcon[$app['key']] ?? 'box', 18) ?></span>
          <div>
            <strong><?= e($app['name']) ?></strong>
            <small><?= e($app['category']) ?></small>
          </div>
        </div>
        <span class="badge <?= $app['installed'] ? 'badge--ok' : 'badge--muted' ?>"><i></i><?= $app['installed'] ? '已安装' : '未安装' ?></span>
      </div>
      <p class="app-card__desc"><?= e($app['desc']) ?></p>
      <div class="app-card__meta">
        <span><?= $app['installed'] ? ('版本 ' . e($app['version'] ?: '未知')) : ('winget：' . e($app['winget'] ?: '无')) ?></span>
        <?php if ($app['installed'] && $app['path'] !== ''): ?>
          <span class="mono" title="<?= e($app['path']) ?>"><?= e(mb_strimwidth($app['path'], 0, 30, '…')) ?></span>
        <?php endif; ?>
      </div>
      <div class="app-card__actions">
        <?php if ($app['installed']): ?>
          <form method="post" action="<?= url('/store/check') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="key" value="<?= e($app['key']) ?>">
            <button class="btn btn--ghost btn--sm" type="submit"><?= icon('refresh', 13) ?>重新检测</button>
          </form>
        <?php else: ?>
          <form method="post" action="<?= url('/store/install') ?>"
            data-confirm="将通过 winget 静默安装 <?= e($app['name']) ?>，安装过程可能需要 1-3 分钟，确认继续？">
            <?= csrf_field() ?>
            <input type="hidden" name="key" value="<?= e($app['key']) ?>">
            <button class="btn btn--primary btn--sm" type="submit" <?= ($platform === 'Windows' && $winget && $app['winget'] !== '') ? '' : 'disabled' ?>>
              <?= icon('download', 13) ?>安装
            </button>
          </form>
        <?php endif; ?>
        <a class="btn btn--ghost btn--sm" href="<?= e($app['url']) ?>" target="_blank" rel="noopener"><?= icon('external', 13) ?>官网</a>
      </div>
    </article>
  <?php endforeach; ?>
</div>

<?php if ($install_logs): ?>
<div class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('terminal', 17) ?>安装日志</span>
    <span class="badge badge--muted"><?= count($install_logs) ?></span>
  </div>
  <div class="card__body">
    <?php foreach ($install_logs as $log): ?>
      <div style="margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;margin-bottom:6px">
          <strong class="small mono"><?= e($log['name']) ?></strong>
          <span class="small muted"><?= e($log['mtime']) ?></span>
        </div>
        <div class="pre"><?= e($log['tail'] ?: '日志为空或安装尚未输出信息') ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
