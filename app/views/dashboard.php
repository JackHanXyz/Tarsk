<?php
declare(strict_types=1);

$cpu = (float)($metrics['cpu'] ?? 0);
$memUsed = (int)($metrics['mem_used'] ?? 0);
$memTotal = (int)($metrics['mem_total'] ?? 0);
$memPct = percent($memUsed, $memTotal);
$diskPct = percent($disk_usage['used'], $disk_usage['total']);
$load = (float)($metrics['load1'] ?? 0);
$uptime = uptime_text((int)($metrics['uptime'] ?? 0));
$drives = $metrics['drives'] ?? [];

$cpuBarClass = $cpu >= 80 ? 'bar__fill--danger' : ($cpu >= 60 ? 'bar__fill--warn' : '');
$memBarClass = $memPct >= 85 ? 'bar__fill--danger' : ($memPct >= 70 ? 'bar__fill--warn' : '');
$diskBarClass = $diskPct >= 85 ? 'bar__fill--danger' : ($diskPct >= 70 ? 'bar__fill--warn' : '');
?>

<section class="stats">
  <article class="stat">
    <div class="stat__head">
      <span class="stat__label"><span class="stat__icon"><?= icon('cpu', 16) ?></span>CPU 使用率</span>
      <span class="badge badge--muted"><?= e($metrics['os_family'] ?? PHP_OS_FAMILY) ?></span>
    </div>
    <div class="stat__value" id="statCpu"><?= number_format($cpu, 1) ?><small>%</small></div>
    <div class="stat__meta"><span>负载 <?= number_format($load, 2) ?></span><span><?= e($metrics['hostname'] ?? '') ?></span></div>
    <div class="bar"><div class="bar__fill <?= $cpuBarClass ?>" id="statCpuBar" style="width:<?= number_format($cpu, 1) ?>%"></div></div>
  </article>

  <article class="stat">
    <div class="stat__head">
      <span class="stat__label"><span class="stat__icon stat__icon--violet"><?= icon('memory', 16) ?></span>内存占用</span>
      <span class="badge badge--muted" id="statMemPct"><?= number_format($memPct, 1) ?>%</span>
    </div>
    <div class="stat__value" id="statMem"><?= bytes($memUsed, 1) ?></div>
    <div class="stat__meta"><span>总内存 <?= bytes($memTotal, 1) ?></span><span id="statMemFree">可用 <?= bytes(max(0, $memTotal - $memUsed), 1) ?></span></div>
    <div class="bar"><div class="bar__fill <?= $memBarClass ?>" id="statMemBar" style="width:<?= number_format($memPct, 1) ?>%"></div></div>
  </article>

  <article class="stat">
    <div class="stat__head">
      <span class="stat__label"><span class="stat__icon stat__icon--ok"><?= icon('disk', 16) ?></span>磁盘占用</span>
      <span class="badge badge--muted">系统盘</span>
    </div>
    <div class="stat__value" id="statDisk"><?= bytes($disk_usage['used'], 1) ?></div>
    <div class="stat__meta"><span>总容量 <?= bytes($disk_usage['total'], 1) ?></span><span id="statDiskPct"><?= number_format($diskPct, 1) ?>%</span></div>
    <div class="bar"><div class="bar__fill <?= $diskBarClass ?>" id="statDiskBar" style="width:<?= number_format($diskPct, 1) ?>%"></div></div>
  </article>

  <article class="stat">
    <div class="stat__head">
      <span class="stat__label"><span class="stat__icon stat__icon--warn"><?= icon('network', 16) ?></span>网络吞吐</span>
      <span class="badge badge--muted">实时</span>
    </div>
    <div class="stat__value" id="statNetDown"><?= bytes((int)($metrics['net_rx'] ?? 0), 1) ?></div>
    <div class="stat__meta"><span>下行</span><span id="statNetUp">上行 —</span></div>
    <div class="stat__meta" style="margin-top:8px"><span class="muted small">开机时长</span><span class="small"><?= e($uptime) ?></span></div>
  </article>
</section>

<section class="grid grid--sidebar mt-18">
  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('activity', 17) ?>资源实时趋势<span class="card__desc">每 <?= (int)$refresh ?> 秒刷新</span></span>
      <span class="badge badge--info" id="metricsClock"><?= e($metrics['time'] ?? date('H:i:s')) ?></span>
    </div>
    <div class="card__body">
      <div class="chart-wrap">
        <canvas class="chart-canvas" id="liveChart" height="210"></canvas>
      </div>
      <div class="legend mt-12">
        <span><i style="background:var(--chart-1)"></i>CPU %</span>
        <span><i style="background:var(--chart-2)"></i>内存 %</span>
        <span><i style="background:var(--chart-3)"></i>磁盘 %</span>
      </div>
    </div>
  </article>

  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('server', 17) ?>服务状态</span>
      <a class="btn btn--ghost btn--sm" href="<?= url('/store') ?>">软件商店</a>
    </div>
    <div class="card__body">
      <?php if (!$services): ?>
        <div class="empty" style="padding:20px 0">
          <?= icon('alert', 26) ?>
          <p>未能读取系统服务列表</p>
          <small>请确认 PowerShell 可用，或手动启动相关服务</small>
        </div>
      <?php else: ?>
        <?php foreach ($services as $service): ?>
          <div class="service-row">
            <div class="svc">
              <span class="dot <?= $service['running'] ? 'dot--ok' : 'dot--off' ?>"></span>
              <strong><?= e($service['display'] ?: $service['name']) ?></strong>
            </div>
            <span class="badge <?= $service['running'] ? 'badge--ok' : 'badge--muted' ?>"><?= $service['running'] ? '运行中' : '未运行' ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <div class="service-row" style="margin-top:6px">
        <div class="svc">
          <span class="dot <?= $mysql['connected'] ? 'dot--ok' : 'dot--off' ?>"></span>
          <strong>MySQL 连接</strong>
        </div>
        <span class="small muted"><?= $mysql['connected'] ? ('v' . e($mysql['version']) . ' · 账号 ' . e($mysql['user'])) : '未连接' ?></span>
      </div>
      <div class="service-row">
        <div class="svc">
          <span class="dot <?= $docker['running'] ? 'dot--ok' : 'dot--off' ?>"></span>
          <strong>Docker</strong>
        </div>
        <span class="small muted"><?= $docker['installed'] ? ($docker['running'] ? '已连接 · ' . e($docker['version']) : 'CLI 已安装，守护进程未就绪') : '未安装' ?></span>
      </div>

      <?php foreach ($runtime as $state): ?>
        <div class="service-row">
          <div class="svc">
            <span class="dot <?= $state['running'] ? 'dot--ok' : ($state['installed'] ? 'dot--warn' : 'dot--off') ?>"></span>
            <strong>托管 <?= e($state['name']) ?></strong>
          </div>
          <span class="small muted">
            <?= $state['installed'] ? ($state['running'] ? '运行中 · 端口 ' . (int)$state['port'] : '已安装 · 未启动') : '未安装' ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  </article>
</section>

<section class="grid grid--sidebar mt-18">
  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('globe', 17) ?>站点速览</span>
      <div class="toolbar">
        <a class="btn btn--ghost btn--sm" href="<?= url('/sites') ?>">全部站点</a>
        <a class="btn btn--primary btn--sm" href="<?= url('/sites') ?>"><?= icon('plus', 13) ?>新建站点</a>
      </div>
    </div>
    <div class="card__body card__body--flush">
      <?php if (!$sites): ?>
        <div class="empty">
          <?= icon('globe', 28) ?>
          <p>还没有创建站点</p>
          <small>站点目录与 Nginx 配置会在创建时自动生成</small>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>站点</th><th>域名</th><th>状态</th><th>创建时间</th></tr></thead>
            <tbody>
            <?php foreach ($sites as $site): ?>
              <tr>
                <td>
                  <div class="cell-main"><?= e($site['name']) ?></div>
                  <div class="cell-sub mono"><?= e($site['path']) ?></div>
                </td>
                <td><?= e($site['domain'] ?: '—') ?></td>
                <td><span class="badge <?= $site['status'] === 'running' ? 'badge--ok' : 'badge--muted' ?>"><i></i><?= $site['status'] === 'running' ? '运行中' : '已停止' ?></span></td>
                <td class="muted small"><?= e(ago($site['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <div class="card__foot">
      <div class="toolbar">
        <a class="btn btn--soft btn--sm" href="<?= url('/files') ?>"><?= icon('folder', 13) ?>文件管理</a>
        <a class="btn btn--soft btn--sm" href="<?= url('/databases') ?>"><?= icon('database', 13) ?>数据库</a>
        <a class="btn btn--soft btn--sm" href="<?= url('/cron') ?>"><?= icon('clock', 13) ?>计划任务</a>
      </div>
    </div>
  </article>

  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('list', 17) ?>最近操作</span>
      <a class="btn btn--ghost btn--sm" href="<?= url('/logs') ?>">查看日志</a>
    </div>
    <div class="card__body">
      <?php if (!$logs): ?>
        <div class="empty" style="padding:24px 0"><?= icon('list', 26) ?><p>暂无操作记录</p></div>
      <?php else: ?>
        <?php foreach ($logs as $log): ?>
          <div class="service-row">
            <div class="svc">
              <span class="dot <?= $log['level'] === 'danger' ? 'dot--warn' : ($log['level'] === 'warning' ? 'dot--warn' : 'dot--ok') ?>"></span>
              <div>
                <strong><?= e($log['action']) ?></strong>
                <div class="small muted"><?= e($log['detail']) ?></div>
              </div>
            </div>
            <span class="small muted"><?= e(ago($log['created_at'])) ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </article>
</section>

<?php if (!empty($drives)): ?>
<section class="card mt-18">
  <div class="card__head"><span class="card__title"><?= icon('disk', 17) ?>磁盘分区</span></div>
  <div class="card__body card__body--flush">
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>分区</th><th>已用</th><th>容量</th><th>使用率</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($drives as $drive): ?>
          <?php $pct = percent(($drive['total'] ?? 0) - ($drive['free'] ?? 0), $drive['total'] ?? 0); ?>
          <tr>
            <td class="cell-main mono"><?= e($drive['name'] ?? '?') ?></td>
            <td><?= bytes(($drive['total'] ?? 0) - ($drive['free'] ?? 0)) ?></td>
            <td><?= bytes($drive['total'] ?? 0) ?></td>
            <td style="width:200px">
              <div class="bar" style="margin:0"><div class="bar__fill <?= $pct >= 85 ? 'bar__fill--danger' : '' ?>" style="width:<?= number_format($pct, 1) ?>%"></div></div>
            </td>
            <td class="muted small"><?= number_format($pct, 1) ?>%</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php endif; ?>

<script>
window.TARSK_DASHBOARD = { refresh: <?= (int)$refresh ?> };
</script>
