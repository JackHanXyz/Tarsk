<?php
declare(strict_types=1);
$okCount = (int)($summary['ok_count'] ?? 0);
$totalRuns = (int)($summary['total'] ?? 0);
$successRate = $totalRuns > 0 ? round($okCount / $totalRuns * 100, 1) : 0.0;
?>
<div class="section-head">
  <div>
    <div class="toolbar" style="gap:8px;margin-bottom:6px">
      <a class="badge badge--muted" href="<?= url('/cron') ?>"><?= icon('arrow-left', 12) ?>计划任务</a>
      <span class="badge <?= $task['status'] === 'enabled' ? 'badge--ok' : 'badge--muted' ?>"><i></i><?= $task['status'] === 'enabled' ? '启用' : '停用' ?></span>
      <span class="badge badge--info"><?= e($type_label) ?></span>
    </div>
    <h2><?= e($task['name']) ?></h2>
    <p>ID <?= (int)$task['id'] ?> · <?= e($schedule_text) ?> · 每个任务保留最近 200 条执行记录</p>
  </div>
  <div class="toolbar">
    <form method="post" action="<?= url('/cron/' . (int)$task['id'] . '/run') ?>">
      <?= csrf_field() ?>
      <button class="btn btn--ghost btn--sm" type="submit"><?= icon('play', 14) ?>立即执行</button>
    </form>
    <form method="post" action="<?= url('/cron/' . (int)$task['id'] . '/history/clear') ?>" data-confirm="将删除该任务的全部执行历史，确认继续？">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
      <button class="btn btn--danger-ghost btn--sm" type="submit"><?= icon('trash', 14) ?>清空历史</button>
    </form>
  </div>
</div>

<section class="stats">
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon"><?= icon('list', 16) ?></span>执行次数</span><span class="badge badge--muted">累计</span></div>
    <div class="stat__value"><?= number_format($totalRuns) ?></div>
    <div class="stat__meta"><span>成功</span><span><?= number_format($okCount) ?></span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--ok"><?= icon('check', 16) ?></span>成功率</span><span class="badge <?= $successRate >= 95 ? 'badge--ok' : ($successRate >= 80 ? 'badge--warn' : 'badge--danger') ?>"><?= number_format($successRate, 1) ?>%</span></div>
    <div class="stat__value"><?= number_format($okCount) ?><small>/ <?= number_format($totalRuns) ?></small></div>
    <div class="stat__meta"><span>失败</span><span><?= number_format($totalRuns - $okCount) ?></span></div>
    <div class="bar"><div class="bar__fill <?= $successRate >= 95 ? 'bar__fill--ok' : ($successRate >= 80 ? 'bar__fill--warn' : 'bar__fill--danger') ?>" style="width:<?= number_format($successRate, 1) ?>%"></div></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--violet"><?= icon('clock', 16) ?></span>平均耗时</span></div>
    <div class="stat__value" style="font-size:21px"><?= $totalRuns > 0 ? number_format((float)($summary['avg_ms'] ?? 0) / 1000, 2) : '—' ?><small>s</small></div>
    <div class="stat__meta"><span>上次执行</span><span><?= e($task['last_run_at'] ? ago($task['last_run_at']) : '从未') ?></span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--warn"><?= icon('terminal', 16) ?></span>最后结果</span></div>
    <div class="stat__value" style="font-size:14px;line-height:1.5"><?= e(mb_strimwidth((string)$task['last_result'], 0, 70, '…') ?: '—') ?></div>
    <div class="stat__meta"><span>目标</span><span><?= e(mb_strimwidth(cron_target_text($task), 0, 28, '…')) ?></span></div>
  </article>
</section>

<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('list', 17) ?>执行记录</span>
    <span class="badge badge--muted">第 <?= (int)$page ?> / <?= (int)$pages ?> 页 · 共 <?= number_format($total) ?> 条</span>
  </div>
  <div class="card__body card__body--flush">
    <?php if (!$rows): ?>
      <div class="empty">
        <?= icon('clock', 30) ?>
        <p>还没有执行记录</p>
        <small>任务被执行（系统调度或手动触发）后会在这里留下记录</small>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th style="width:150px">时间</th><th style="width:70px">结果</th><th style="width:70px">触发</th><th style="width:80px">耗时</th><th>消息 / 输出</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td class="small mono"><?= e(date('Y-m-d H:i:s', strtotime($row['started_at']))) ?></td>
              <td><span class="badge <?= (int)$row['ok'] === 1 ? 'badge--ok' : 'badge--danger' ?>"><?= (int)$row['ok'] === 1 ? '成功' : '失败' ?></span></td>
              <td class="small"><?= $row['trigger_by'] === 'manual' ? '手动' : '调度' ?></td>
              <td class="small mono"><?= (int)$row['duration_ms'] >= 1000 ? number_format((int)$row['duration_ms'] / 1000, 2) . 's' : (int)$row['duration_ms'] . 'ms' ?></td>
              <td>
                <div class="cell-main small"><?= e($row['message']) ?></div>
                <?php if (trim((string)$row['output']) !== ''): ?>
                  <details>
                    <summary class="cell-sub" style="cursor:pointer">查看输出</summary>
                    <pre class="pre pre--light mt-12" style="max-height:260px;overflow:auto"><?= e(mb_strimwidth((string)$row['output'], 0, 4000, "\n…（已截断）")) ?></pre>
                  </details>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  <?php if ($pages > 1): ?>
    <div class="card__foot" style="display:flex;justify-content:space-between;align-items:center">
      <span class="hint">共 <?= (int)$pages ?> 页</span>
      <div class="toolbar">
        <?php if ($page > 1): ?>
          <a class="btn btn--ghost btn--sm" href="<?= url('/cron/' . (int)$task['id'] . '/history?page=' . ($page - 1)) ?>">上一页</a>
        <?php endif; ?>
        <?php if ($page < $pages): ?>
          <a class="btn btn--ghost btn--sm" href="<?= url('/cron/' . (int)$task['id'] . '/history?page=' . ($page + 1)) ?>">下一页</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</section>
