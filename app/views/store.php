<?php
declare(strict_types=1);

$statusMeta = [
    'missing' => ['未安装', 'badge--muted'],
    'installing' => ['安装中', 'badge--warn'],
    'stopped' => ['已安装 / 未启动', 'badge--muted'],
    'running' => ['运行中', 'badge--ok'],
];
$installedCount = count(array_filter($apps, fn($a) => $a['installed']));
?>
<div class="section-head">
  <div>
    <h2>软件商店</h2>
    <p>Tarsk 可直接下载并接管 Nginx / PHP / Redis，站点配置会自动同步；其余系统软件支持检测与 winget 安装。</p>
  </div>
  <div class="toolbar">
    <a class="btn btn--ghost btn--sm" href="<?= url('/sites') ?>"><?= icon('globe', 14) ?>站点管理</a>
    <form method="post" action="<?= url('/store/check') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="key" value="all">
      <button class="btn btn--ghost btn--sm" type="submit"><?= icon('refresh', 14) ?>重新检测</button>
    </form>
  </div>
</div>

<?php if ($platform !== 'Windows'): ?>
  <div class="alert alert--warning"><?= icon('alert', 16) ?><span>托管安装与 winget 安装仅支持 Windows；检测与其他功能不受影响。</span></div>
<?php endif; ?>

<div class="section-head" style="margin-top:4px">
  <div>
    <h3 style="margin:0;font-family:var(--font-display);font-size:15px">运行环境（Tarsk 托管）</h3>
    <p>安装包会下载到面板 storage/soft 目录，进程由面板直接管理，卸载不残留。</p>
  </div>
</div>

<div class="app-grid managed-grid">
  <?php foreach ($managed as $key => $state): ?>
    <?php [$statusText, $statusClass] = $statusMeta[$state['status']] ?? ['未知', 'badge--muted']; ?>
    <article class="app-card managed-card">
      <div class="app-card__head">
        <div class="app-card__id">
          <span class="app-ico"><?= icon($state['icon'], 18) ?></span>
          <div>
            <strong><?= e($state['name']) ?></strong>
            <small>Managed · <?= e($state['version']) ?></small>
          </div>
        </div>
        <span class="badge <?= $statusClass ?>"><i></i><?= e($statusText) ?></span>
      </div>
      <p class="app-card__desc"><?= e($state['desc']) ?></p>
      <div class="app-card__meta">
        <span>端口 <strong class="mono" style="color:var(--text-2)"><?= (int)$state['port'] ?></strong><?= $state['pid'] ? ' · PID ' . (int)$state['pid'] : '' ?></span>
        <span class="mono" title="<?= e($state['path']) ?>"><?= e($state['installed'] ? mb_strimwidth($state['path'], 0, 34, '…') : '安装体积 ' . $state['size']) ?></span>
      </div>
      <div class="app-card__actions">
        <?php if (!$state['installed']): ?>
          <form method="post" action="<?= url('/store/install-managed') ?>" data-busy="正在下载并安装，请勿关闭页面…"
            data-confirm="将下载 <?= e($state['name']) ?>（<?= e($state['size']) ?>）并安装到 storage/soft，确认继续？">
            <?= csrf_field() ?>
            <input type="hidden" name="key" value="<?= e($key) ?>">
            <button class="btn btn--primary btn--sm" type="submit" <?= $platform === 'Windows' ? '' : 'disabled' ?>><?= icon('download', 13) ?>安装</button>
          </form>
        <?php else: ?>
          <?php if ($state['running']): ?>
            <form method="post" action="<?= url('/store/managed-action') ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="key" value="<?= e($key) ?>">
              <input type="hidden" name="action" value="stop">
              <button class="btn btn--ghost btn--sm" type="submit"><?= icon('stop', 13) ?>停止</button>
            </form>
          <?php else: ?>
            <form method="post" action="<?= url('/store/managed-action') ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="key" value="<?= e($key) ?>">
              <input type="hidden" name="action" value="start">
              <button class="btn btn--primary btn--sm" type="submit"><?= icon('play', 13) ?>启动</button>
            </form>
          <?php endif; ?>
          <form method="post" action="<?= url('/store/managed-action') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="key" value="<?= e($key) ?>">
            <input type="hidden" name="action" value="restart">
            <button class="btn btn--ghost btn--sm" type="submit"><?= icon('refresh', 13) ?>重启</button>
          </form>
          <button class="btn btn--ghost btn--sm" type="button" data-store-log="<?= e($key) ?>" data-store-name="<?= e($state['name']) ?>"><?= icon('terminal', 13) ?>日志</button>
          <form method="post" action="<?= url('/store/managed-action') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="key" value="<?= e($key) ?>">
            <input type="hidden" name="action" value="open">
            <button class="btn btn--ghost btn--sm" type="submit"><?= icon('folder', 13) ?>目录</button>
          </form>
          <form method="post" action="<?= url('/store/managed-action') ?>"
            data-confirm="卸载 <?= e($state['name']) ?> 会停止进程并删除整个安装目录，确认继续？">
            <?= csrf_field() ?>
            <input type="hidden" name="key" value="<?= e($key) ?>">
            <input type="hidden" name="action" value="uninstall">
            <button class="btn btn--danger-ghost btn--sm" type="submit"><?= icon('trash', 13) ?>卸载</button>
          </form>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>

<div class="section-head" style="margin-top:26px">
  <div>
    <h3 style="margin:0;font-family:var(--font-display);font-size:15px">系统软件</h3>
    <p>检测本机已有环境；缺失项可通过 winget 静默安装（MySQL 已作为面板数据库服务在使用）。</p>
  </div>
  <span class="badge badge--muted">已安装 <?= $installedCount ?> / <?= count($apps) ?></span>
</div>

<?php if (!$winget && $platform === 'Windows'): ?>
  <div class="alert alert--warning"><?= icon('alert', 16) ?><span>未检测到 winget，系统软件安装按钮不可用。可在应用商店更新「应用安装程序」，或前往官网下载。</span></div>
<?php endif; ?>

<div class="app-grid">
  <?php foreach ($apps as $app): ?>
    <article class="app-card">
      <div class="app-card__head">
        <div class="app-card__id">
          <span class="app-ico"><?= icon('box', 18) ?></span>
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
          <form method="post" action="<?= url('/store/install') ?>" data-busy="正在后台启动安装…"
            data-confirm="将通过 winget 静默安装 <?= e($app['name']) ?>，安装过程可能需要 1-3 分钟，确认继续？">
            <?= csrf_field() ?>
            <input type="hidden" name="key" value="<?= e($app['key']) ?>">
            <button class="btn btn--primary btn--sm" type="submit" <?= ($platform === 'Windows' && $winget && $app['winget'] !== '') ? '' : 'disabled' ?>><?= icon('download', 13) ?>安装</button>
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
    <span class="card__title"><?= icon('terminal', 17) ?>winget 安装日志</span>
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

<dialog class="modal modal--wide" id="storeLogDialog">
  <div class="modal__head">
    <h3 id="storeLogTitle">运行日志</h3>
    <button class="icon-btn" type="button" data-close="storeLogDialog"><?= icon('x', 14) ?></button>
  </div>
  <div class="modal__body">
    <pre class="pre" id="storeLogTail" style="max-height:52vh">加载中…</pre>
  </div>
  <div class="modal__foot">
    <span class="small muted" id="storeLogPath"></span>
    <button class="btn btn--ghost" type="button" data-close="storeLogDialog">关闭</button>
  </div>
</dialog>
