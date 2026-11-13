<?php
declare(strict_types=1);

/**
 * 系统工具页。
 * @var array $timezone
 * @var array $tzOptions
 * @var array $disks
 * @var array $swap
 * @var array $ssh
 * @var array $optimizes
 */
?>
<div class="section-head">
  <div>
    <h2>系统工具</h2>
    <p>时区、磁盘、swap / 页面文件、SSH 与常用系统优化。能力按平台提供，不存在的项会明确标注。</p>
  </div>
  <div class="toolbar">
    <a class="btn btn--ghost btn--sm" href="<?= url('/system') ?>"><?= icon('refresh', 14) ?>刷新</a>
  </div>
</div>

<div class="grid grid-2 mt-18">
  <section class="card">
    <div class="card__head"><span class="card__title"><?= icon('clock', 17) ?>时区</span></div>
    <div class="card__body">
      <p class="small">系统时区：<strong class="mono"><?= e($timezone['current']) ?></strong>（Windows 标识 <span class="mono"><?= e($timezone['raw'] ?: '—') ?></span>）</p>
      <p class="small muted">PHP 时区：<?= e($timezone['php']) ?></p>
      <form method="post" action="<?= url('/system/timezone') ?>" class="toolbar mt-12">
        <?= csrf_field() ?>
        <select class="input" name="tz" style="max-width:260px">
          <?php foreach ($tzOptions as $tz): ?>
            <option value="<?= e($tz) ?>" <?= $tz === $timezone['current'] ? 'selected' : '' ?>><?= e($tz) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn--primary btn--sm" type="submit">设置时区</button>
      </form>
    </div>
  </section>

  <section class="card">
    <div class="card__head"><span class="card__title"><?= icon('cpu', 17) ?>SSH</span></div>
    <div class="card__body">
      <?php if (!$ssh['installed']): ?>
        <p class="small muted"><?= e($ssh['note']) ?></p>
      <?php else: ?>
        <p class="small">
          状态：<span class="badge badge--<?= $ssh['running'] ? 'ok' : 'danger' ?>"><?= $ssh['running'] ? '运行中' : '未运行' ?></span>
          端口：<strong class="mono"><?= (int)($ssh['port'] ?? 22) ?></strong>
        </p>
        <p class="small muted mt-6"><?= e($ssh['note']) ?></p>
      <?php endif; ?>
    </div>
  </section>
</div>

<section class="card mt-18">
  <div class="card__head"><span class="card__title"><?= icon('server', 17) ?>磁盘 / 挂载</span></div>
  <div class="card__body card__body--flush">
    <?php if (!$disks): ?>
      <div class="empty">未读取到磁盘信息</div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>挂载点</th><th>设备 / 卷标</th><th>总容量</th><th>可用</th><th>使用率</th></tr></thead>
          <tbody>
          <?php foreach ($disks as $d): ?>
            <?php $pct = $d['total'] > 0 ? round(($d['total'] - $d['free']) / $d['total'] * 100, 1) : 0; ?>
            <tr>
              <td class="cell-main mono"><?= e($d['mount']) ?></td>
              <td><?= e($d['label'] ?: '—') ?></td>
              <td><?= bytes($d['total']) ?></td>
              <td><?= bytes($d['free']) ?></td>
              <td style="min-width:160px">
                <div class="bar"><div class="bar__fill <?= $pct >= 90 ? '' : '' ?>" style="width:<?= $pct ?>%"></div></div>
                <span class="small <?= $pct >= 90 ? 'danger' : 'muted' ?>"><?= $pct ?>%</span>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('memory', 17) ?><?= $swap['platform'] === 'windows' ? '页面文件' : 'Swap' ?></span>
  </div>
  <div class="card__body">
    <p class="small muted"><?= e($swap['note']) ?></p>
    <?php if ($swap['files']): ?>
      <div class="table-wrap mt-12">
        <table class="table">
          <thead><tr><th>位置</th><th>大小</th><th>使用中</th></tr></thead>
          <tbody>
          <?php foreach ($swap['files'] as $f): ?>
            <tr>
              <td class="cell-main mono"><?= e($f['name']) ?></td>
              <td><?= bytes($f['size']) ?></td>
              <td><?= bytes($f['used']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <?php if ($swap['swappiness'] !== null): ?>
      <form method="post" action="<?= url('/system/swap') ?>" class="toolbar mt-12">
        <?= csrf_field() ?>
        <span class="small">swappiness：<strong class="mono"><?= (int)$swap['swappiness'] ?></strong></span>
        <select class="input" name="value" style="max-width:120px">
          <?php foreach ([1, 10, 30, 60] as $v): ?>
            <option value="<?= $v ?>"><?= $v ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn--ghost btn--sm" type="submit">调整</button>
        <span class="hint">需要 root；同时写入 /etc/sysctl.d/99-tarsk-swap.conf 持久化。</span>
      </form>
    <?php endif; ?>
  </div>
</section>

<section class="card mt-18">
  <div class="card__head"><span class="card__title"><?= icon('activity', 17) ?>系统优化</span></div>
  <div class="card__body card__body--flush">
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>项目</th><th>说明</th><th style="text-align:right">操作</th></tr></thead>
        <tbody>
        <?php foreach ($optimizes as $o): ?>
          <tr>
            <td class="cell-main"><strong><?= e($o['label']) ?></strong></td>
            <td class="small muted"><?= e($o['desc']) ?></td>
            <td>
              <form method="post" action="<?= url('/system/optimize') ?>" data-confirm="确认执行「<?= e($o['label']) ?>」？" style="text-align:right">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= e($o['id']) ?>">
                <button class="btn btn--ghost btn--sm" type="submit">执行</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card__foot"><span class="small muted">优化动作需要管理员 / root 权限；失败时面板会显示具体原因。</span></div>
</section>
