<?php
declare(strict_types=1);

$cpu = (float)($metrics['cpu'] ?? 0);
$memPct = percent($metrics['mem_used'] ?? 0, $metrics['mem_total'] ?? 0);
$diskPct = percent($metrics['disk_used'] ?? 0, $metrics['disk_total'] ?? 0);
$sampleCount = count($series);
?>
<div class="section-head">
  <div>
    <h2>系统监控</h2>
    <p>采集真实 CPU、内存、磁盘与网络指标，每 30 秒记录一次采样。</p>
  </div>
  <div class="toolbar">
    <span class="chip chip--ok" id="monitorStatusChip"><i></i><span id="monitorStatus" aria-live="polite">正在连接监控数据…</span></span>
    <a class="btn btn--ghost btn--sm" href="<?= url('/monitor') ?>"><?= icon('refresh', 14) ?>刷新数据</a>
  </div>
</div>

<section class="stats">
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon"><?= icon('cpu', 16) ?></span>CPU</span><span class="badge badge--muted" id="monCpuTick"><?= e($metrics['time'] ?? '') ?></span></div>
    <div class="stat__value" id="monCpu"><?= number_format($cpu, 1) ?><small>%</small></div>
    <div class="stat__meta"><span>1 / 5 / 15 分钟负载</span><span><b id="monLoad1"><?= number_format((float)($metrics['load1'] ?? 0), 2) ?></b> / <span id="monLoad5"><?= number_format((float)($metrics['load5'] ?? 0), 2) ?></span> / <span id="monLoad15"><?= number_format((float)($metrics['load15'] ?? 0), 2) ?></span></span></div>
    <div class="stat__meta"><span>CPU 核心</span><span id="monCpuCores"><?= (int)($metrics['cpu_cores'] ?? 0) ?: '—' ?></span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--violet"><?= icon('memory', 16) ?></span>内存</span><span class="badge badge--muted" id="monMemPct"><?= number_format($memPct, 1) ?>%</span></div>
    <div class="stat__value" id="monMem"><?= bytes($metrics['mem_used'] ?? 0) ?></div>
    <div class="stat__meta"><span>总内存</span><span><?= bytes($metrics['mem_total'] ?? 0) ?></span></div>
    <div class="bar"><div class="bar__fill" id="monMemBar" style="width:<?= number_format($memPct, 1) ?>%"></div></div>
    <div class="stat__meta"><span>Swap 已用 / 总量</span><span id="monSwap"><?= bytes($metrics['swap_used'] ?? 0) ?> / <?= bytes($metrics['swap_total'] ?? 0) ?></span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--ok"><?= icon('disk', 16) ?></span>磁盘</span><span class="badge badge--muted" id="monDiskPct"><?= number_format($diskPct, 1) ?>%</span></div>
    <div class="stat__value" id="monDisk"><?= bytes($metrics['disk_used'] ?? 0) ?></div>
    <div class="stat__meta"><span>总容量</span><span><?= bytes($metrics['disk_total'] ?? 0) ?></span></div>
    <div class="bar"><div class="bar__fill bar__fill--ok" id="monDiskBar" style="width:<?= number_format($diskPct, 1) ?>%"></div></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--warn"><?= icon('clock', 16) ?></span>运行时长</span></div>
    <div class="stat__value" style="font-size:20px"><?= e(uptime_text((int)($metrics['uptime'] ?? 0))) ?></div>
    <div class="stat__meta"><span>主机</span><span><?= e($metrics['hostname'] ?? '') ?></span></div>
  </article>
</section>

<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('activity', 17) ?>网络流量</span>
    <span class="badge badge--muted">接收 <strong id="monNetDown">—</strong> · 发送 <strong id="monNetUp">—</strong></span>
  </div>
  <div class="card__body" id="monNetInterfaces">
    <?php if (empty($metrics['net_interfaces'])): ?>
      <div class="service-row"><span class="muted">暂未读取到网卡明细；总计 RX <?= bytes($metrics['net_rx'] ?? 0) ?> · TX <?= bytes($metrics['net_tx'] ?? 0) ?></span></div>
    <?php else: foreach ($metrics['net_interfaces'] as $iface): ?>
      <div class="service-row"><div class="svc"><span class="dot dot--ok"></span><strong class="mono"><?= e($iface['name']) ?></strong></div><span class="small muted">RX <?= bytes($iface['rx']) ?> · TX <?= bytes($iface['tx']) ?></span></div>
    <?php endforeach; endif; ?>
  </div>
</section>

<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('activity', 17) ?><?= e($rangeLabel ?? '24 小时') ?>资源曲线<span class="card__desc"><?= $sampleCount ?> 个<?= e($seriesUnit ?? '采样') ?>点</span></span>
    <div class="toolbar">
      <?php foreach (($rangeLabels ?? []) as $key => $label): ?>
        <a class="btn btn--sm <?= ($range ?? '24h') === $key ? '' : 'btn--ghost' ?>" href="<?= url('/monitor?range=' . $key) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if (!empty($digest) && ($digest['today_cpu'] !== null || $digest['yday_cpu'] !== null)): ?>
  <div class="card__body" style="padding-bottom:0">
    <div class="service-row" style="border:0;padding-bottom:0">
      <span class="small muted">今日 CPU 均值 / 峰值</span>
      <span class="small"><strong><?= $digest['today_cpu'] === null ? '—' : number_format($digest['today_cpu'], 1) . '%' ?></strong>
        / <?= $digest['today_cpu_max'] === null ? '—' : number_format($digest['today_cpu_max'], 1) . '%' ?>
        <span class="muted">（昨日均值 <?= $digest['yday_cpu'] === null ? '—' : number_format($digest['yday_cpu'], 1) . '%' ?>）</span></span>
    </div>
  </div>
  <?php endif; ?>
  <div class="legend" style="padding:0 18px 6px">
    <span><i style="background:var(--chart-1)"></i>CPU</span>
    <span><i style="background:var(--chart-2)"></i>内存</span>
    <span><i style="background:var(--chart-3)"></i>磁盘</span>
  </div>
  <div class="card__body">
    <?php if ($sampleCount === 0): ?>
      <div class="empty">
        <?= icon('activity', 28) ?>
        <p>还没有足够的采样数据</p>
        <small>保持面板访问，系统会自动积累趋势数据</small>
      </div>
    <?php endif; ?>
    <canvas class="chart-canvas" id="monitorChart" style="height:260px"></canvas>
  </div>
</section>

<section class="grid grid--2 mt-18">
  <article class="card">
    <div class="card__head"><span class="card__title"><?= icon('server', 17) ?>服务进程</span></div>
    <div class="card__body">
      <?php if (!$services): ?>
        <div class="empty" style="padding:18px"><?= icon('alert', 24) ?><p>未能读取服务状态</p></div>
      <?php else: ?>
        <?php foreach ($services as $service): ?>
          <div class="service-row">
            <div class="svc"><span class="dot <?= $service['running'] ? 'dot--ok' : 'dot--off' ?>"></span><strong><?= e($service['display']) ?></strong></div>
            <span class="badge <?= $service['running'] ? 'badge--ok' : 'badge--muted' ?>"><?= $service['running'] ? '运行中' : '未运行' ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </article>

  <article class="card">
    <div class="card__head"><span class="card__title"><?= icon('disk', 17) ?>磁盘分区</span></div>
    <div class="card__body">
      <?php if (!$drives): ?>
        <div class="service-row">
          <div class="svc"><span class="dot dot--ok"></span><strong>根分区</strong></div>
          <span class="small muted"><?= bytes($metrics['disk_used'] ?? 0) ?> / <?= bytes($metrics['disk_total'] ?? 0) ?></span>
        </div>
      <?php else: ?>
        <?php foreach ($drives as $drive): ?>
          <?php $pct = percent(($drive['total'] ?? 0) - ($drive['free'] ?? 0), $drive['total'] ?? 0); ?>
          <div style="padding:9px 0">
            <div style="display:flex;justify-content:space-between;font-size:12.5px">
              <span class="mono"><?= e($drive['name'] ?? '') ?></span>
              <span class="muted"><?= bytes(($drive['total'] ?? 0) - ($drive['free'] ?? 0)) ?> / <?= bytes($drive['total'] ?? 0) ?></span>
            </div>
            <div class="bar"><div class="bar__fill <?= $pct >= 85 ? 'bar__fill--danger' : '' ?>" style="width:<?= number_format($pct, 1) ?>%"></div></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </article>
</section>

<script>
window.TARSK_MONITOR = <?= json_encode(['series' => $series, 'interfaces' => $metrics['net_interfaces'] ?? []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.TARSK_REFRESH_INTERVAL = <?= (int)setting('refresh_interval', 3) ?>;
</script>
