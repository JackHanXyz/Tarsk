<?php
declare(strict_types=1);

$memPct = percent($metrics['mem_used'] ?? 0, $metrics['mem_total'] ?? 0);
?>
<div class="section-head">
  <div>
    <h2>进程管理</h2>
    <p>按内存占用排序展示真实进程，可结束指定 PID。数据来自 <?= e(PHP_OS_FAMILY) ?> 系统。</p>
  </div>
  <div class="toolbar">
    <a class="btn btn--ghost btn--sm" href="<?= url('/processes') ?>"><?= icon('refresh', 14) ?>刷新</a>
  </div>
</div>

<section class="stats">
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon"><?= icon('cpu', 16) ?></span>进程总数</span></div>
    <div class="stat__value"><?= (int)$total ?><small>个</small></div>
    <div class="stat__meta"><span>当前列出前 <?= count($items) ?> 个高内存进程</span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--violet"><?= icon('memory', 16) ?></span>内存占用</span></div>
    <div class="stat__value"><?= number_format($memPct, 1) ?><small>%</small></div>
    <div class="stat__meta"><span><?= bytes($metrics['mem_used'] ?? 0) ?> / <?= bytes($metrics['mem_total'] ?? 0) ?></span></div>
    <div class="bar"><div class="bar__fill" style="width:<?= number_format($memPct, 1) ?>%"></div></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--ok"><?= icon('activity', 16) ?></span>CPU</span></div>
    <div class="stat__value"><?= number_format((float)($metrics['cpu'] ?? 0), 1) ?><small>%</small></div>
    <div class="stat__meta"><span>负载 <?= number_format((float)($metrics['load1'] ?? 0), 2) ?></span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--warn"><?= icon('server', 16) ?></span>运行时长</span></div>
    <div class="stat__value" style="font-size:19px"><?= e(uptime_text((int)($metrics['uptime'] ?? 0))) ?></div>
    <div class="stat__meta"><span><?= e($metrics['hostname'] ?? '') ?></span></div>
  </article>
</section>

<div class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('list', 17) ?>进程列表</span>
    <div class="search-box">
      <?= icon('search', 14) ?>
      <input type="search" placeholder="搜索进程名" data-table-search="processTable">
    </div>
  </div>
  <div class="card__body card__body--flush">
    <?php if (!$items): ?>
      <div class="empty">
        <?= icon('cpu', 30) ?>
        <p>未能读取进程列表</p>
        <small><?= e($error ?: '请确认 PowerShell / ps 可用') ?></small>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table" id="processTable">
          <thead>
            <tr><th>PID</th><th>进程</th><th>CPU 时间(s)</th><th>内存</th><th>启动时间</th><th style="text-align:right">操作</th></tr>
          </thead>
          <tbody>
          <?php foreach ($items as $item): ?>
            <tr data-search="<?= e(strtolower($item['name'])) ?>">
              <td class="mono"><?= (int)$item['id'] ?></td>
              <td class="cell-main"><?= e($item['name']) ?></td>
              <td class="mono small"><?= number_format((float)$item['cpu'], 2) ?></td>
              <td><?= $item['mem'] > 0 ? bytes($item['mem']) : '—' ?></td>
              <td class="small muted mono"><?= e($item['started'] ?: '—') ?></td>
              <td>
                <div class="td-actions">
                  <form method="post" action="<?= url('/processes/kill') ?>"
                    data-confirm="确定结束进程 <?= e($item['name']) ?>（PID <?= (int)$item['id'] ?>）？未保存的数据可能丢失。">
                    <?= csrf_field() ?>
                    <input type="hidden" name="pid" value="<?= (int)$item['id'] ?>">
                    <input type="hidden" name="name" value="<?= e($item['name']) ?>">
                    <button class="icon-btn icon-btn--danger" type="submit" title="结束进程"><?= icon('stop', 13) ?></button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  <div class="card__foot"><span class="small muted">结束系统关键进程可能导致服务中断，请谨慎操作。</span></div>
</div>
