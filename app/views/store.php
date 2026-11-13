<?php
declare(strict_types=1);

$statusMeta = [
    'missing' => ['未安装', 'badge--muted'],
    'installing' => ['安装中', 'badge--warn'],
    'stopped' => ['已安装 / 未启动', 'badge--muted'],
    'running' => ['运行中', 'badge--ok'],
];
$installedCount = count(array_filter($apps, fn($a) => $a['installed']));
$linuxManager = $platform === 'Linux' ? store_linux_manager() : null;
?>
<div class="section-head">
  <div>
    <h2>软件商店</h2>
    <p><?= $platform === 'Windows' ? 'Tarsk 托管 Nginx / PHP / Redis，并可通过 winget 安装系统软件。' : '检测 Linux 系统软件，并通过本机包管理器异步安装（需 root 权限）。' ?></p>
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

<?php if ($platform === 'Linux'): ?>
  <div class="alert alert--warning"><?= icon('alert', 16) ?><span>Linux 安装会调用检测到的 <?= e($linuxManager['key'] ?? '系统包管理器') ?>；服务需以 root 身份运行，Tarsk 不使用 sudo。安装任务在后台执行，可从下方读取状态和日志。</span></div>
<?php elseif ($platform !== 'Windows'): ?>
  <div class="alert alert--warning"><?= icon('alert', 16) ?><span>当前平台仅支持软件检测；自动安装暂不可用。</span></div>
<?php endif; ?>

<?php if ($managed): ?>
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
<?php endif; ?>

<div class="section-head" style="margin-top:26px">
  <div>
    <h3 style="margin:0;font-family:var(--font-display);font-size:15px">系统软件</h3>
    <p><?= $platform === 'Windows' ? '检测本机已有环境；缺失项可通过 winget 静默安装。' : '检测本机已有环境；缺失项可通过系统包管理器一键安装。' ?></p>
  </div>
  <span class="badge badge--muted">已安装 <?= $installedCount ?> / <?= count($apps) ?></span>
</div>

<?php if (!$winget && $platform === 'Windows'): ?>
  <div class="alert alert--warning"><?= icon('alert', 16) ?><span>未检测到 winget，系统软件安装按钮不可用。可在应用商店更新「应用安装程序」，或前往官网下载。</span></div>
<?php endif; ?>

<div class="app-grid">
  <?php foreach ($apps as $app): ?>
    <?php $canInstall = $platform === 'Windows' ? ($winget && $app['winget'] !== '') : ($platform === 'Linux' && !empty($app['linux_installable'])); ?>
    <?php $installState = (string)($app['install_status']['status'] ?? ''); ?>
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
        <?php if ($installState !== ''): ?><span class="badge <?= $installState === 'succeeded' ? 'badge--ok' : ($installState === 'failed' ? 'badge--danger' : 'badge--warn') ?>"><?= $installState === 'succeeded' ? '安装成功' : ($installState === 'failed' ? '安装失败' : '安装中') ?></span><?php endif; ?>
      </div>
      <p class="app-card__desc"><?= e($app['desc']) ?></p>
      <div class="app-card__meta">
        <span><?= $app['installed'] ? ('版本 ' . e($app['version'] ?: '未知')) : ($platform === 'Windows' ? ('winget：' . e($app['winget'] ?: '无')) : ('Linux · ' . e($linuxManager['key'] ?? '未检测到包管理器'))) ?></span>
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
            data-confirm="将通过 <?= e($platform === 'Windows' ? 'winget' : ($linuxManager['key'] ?? 'Linux 包管理器')) ?> 安装 <?= e($app['name']) ?>；Linux 安装需要 root 权限，确认继续？">
            <?= csrf_field() ?>
            <input type="hidden" name="key" value="<?= e($app['key']) ?>">
            <button class="btn btn--primary btn--sm" type="submit" <?= $canInstall ? '' : 'disabled' ?>><?= icon('download', 13) ?>安装</button>
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
    <span class="card__title"><?= icon('terminal', 17) ?>软件安装任务</span>
    <span class="badge badge--muted"><?= count($install_logs) ?></span>
  </div>
  <div class="card__body">
    <?php foreach ($install_logs as $log): ?>
      <div style="margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;margin-bottom:6px">
          <strong class="small mono"><?= e($log['name']) ?></strong>
          <span class="small muted"><?= e($log['status'] ?? '未知状态') ?> · <?= e($log['mtime']) ?></span>
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
