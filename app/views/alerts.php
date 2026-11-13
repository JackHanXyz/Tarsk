<?php
declare(strict_types=1);
$levelText = ['info' => '信息', 'warning' => '警告', 'danger' => '危险'];
$levelBadge = ['info' => 'badge--info', 'warning' => 'badge--warn', 'danger' => 'badge--danger'];
$maxDay = 0;
foreach ($series as $day) {
    $maxDay = max($maxDay, (int)$day['count']);
}
?>
<div class="section-head">
  <div>
    <h2>告警历史</h2>
    <p>资源阈值、站点巡检等告警都会落库，可按类型、级别和时间回溯，不再是一次性的推送。</p>
  </div>
  <div class="toolbar">
    <?php if ($channels): ?>
      <span class="badge badge--ok"><i></i>通知渠道已启用</span>
    <?php else: ?>
      <span class="badge badge--muted">未配置通知渠道（只记录不推送）</span>
    <?php endif; ?>
    <a class="btn btn--ghost btn--sm" href="<?= url('/settings') ?>"><?= icon('settings', 14) ?>通知设置</a>
  </div>
</div>

<section class="stats">
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon"><?= icon('clock', 16) ?></span>最近 24 小时</span><span class="badge badge--muted">条</span></div>
    <div class="stat__value"><?= number_format((int)$stats['last24']) ?></div>
    <div class="stat__meta"><span>最近 7 天</span><span><?= number_format((int)$stats['last7d']) ?></span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--warn"><?= icon('alert', 16) ?></span>7 天内危险级</span><span class="badge <?= (int)$stats['danger'] > 0 ? 'badge--danger' : 'badge--muted' ?>">danger</span></div>
    <div class="stat__value"><?= number_format((int)$stats['danger']) ?></div>
    <div class="stat__meta"><span>历史累计</span><span><?= number_format((int)$stats['total']) ?></span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--violet"><?= icon('activity', 16) ?></span>趋势峰值</span><span class="badge badge--muted">近 14 天</span></div>
    <div class="stat__value"><?= number_format($maxDay) ?></div>
    <div class="stat__meta"><span>单日最多</span><span><?= $maxDay > 0 ? '条告警' : '暂无' ?></span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--ok"><?= icon('shield', 16) ?></span>保留策略</span></div>
    <div class="stat__value" style="font-size:19px">180 天</div>
    <div class="stat__meta"><span>上限</span><span>5000 条</span></div>
  </article>
</section>

<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('activity', 17) ?>近 14 天告警趋势</span>
    <div class="legend">
      <span><i style="background:var(--chart-1)"></i>告警数</span>
    </div>
  </div>
  <div class="card__body">
    <div style="display:flex;align-items:flex-end;gap:6px;height:130px;padding-top:6px">
      <?php foreach ($series as $day): ?>
        <?php $h = $maxDay > 0 ? (int)$day['count'] / $maxDay * 100 : 0; ?>
        <div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;height:100%"
             title="<?= e($day['date']) ?>：<?= (int)$day['count'] ?> 条（危险 <?= (int)$day['danger'] ?>）">
          <div style="width:100%;min-height:2px;height:<?= number_format(max(1.5, $h), 1) ?>%;border-radius:3px 3px 0 0;background:<?= (int)$day['danger'] > 0 ? 'linear-gradient(180deg,var(--danger),rgba(184,77,72,.35))' : 'linear-gradient(180deg,var(--chart-1),rgba(217,110,78,.3))' ?>"></div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="toolbar" style="justify-content:space-between;margin-top:6px">
      <span class="hint mono"><?= e($series[0]['label'] ?? '') ?></span>
      <span class="hint mono"><?= e($series[count($series) - 1]['label'] ?? '') ?></span>
    </div>
  </div>
</section>

<div class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('list', 17) ?>记录</span>
    <span class="badge badge--muted">共 <?= number_format($total) ?> 条</span>
  </div>
  <div class="card__body">
    <div class="toolbar" style="flex-wrap:wrap">
      <form class="toolbar" method="get" action="<?= url('/alerts') ?>">
        <select class="input" name="kind" style="height:32px;width:auto">
          <option value="">全部类型</option>
          <?php foreach ($kinds as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= $kind === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <select class="input" name="level" style="height:32px;width:auto">
          <option value="">全部级别</option>
          <?php foreach ($levelText as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= $level === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <input class="input" type="date" name="from" value="<?= e($from) ?>" style="height:32px;width:auto">
        <input class="input" type="date" name="to" value="<?= e($to) ?>" style="height:32px;width:auto">
        <button class="btn btn--ghost btn--sm" type="submit">筛选</button>
        <a class="btn btn--ghost btn--sm" href="<?= url('/alerts') ?>">重置</a>
      </form>
      <form method="post" action="<?= url('/alerts/clear') ?>" data-confirm="将按选择的策略删除告警历史，确认继续？" class="toolbar">
        <?= csrf_field() ?>
        <select class="input" name="days" style="height:32px;width:auto">
          <option value="30">删除 30 天前</option>
          <option value="90">删除 90 天前</option>
          <option value="0">清空全部</option>
        </select>
        <button class="btn btn--danger-ghost btn--sm" type="submit"><?= icon('trash', 14) ?>清理</button>
      </form>
    </div>
  </div>
  <div class="card__body card__body--flush">
    <?php if (!$rows): ?>
      <div class="empty">
        <?= icon('shield', 30) ?>
        <p>没有告警记录</p>
        <small>资源超过阈值或站点巡检失败时会自动记录</small>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th style="width:150px">时间</th><th style="width:90px">级别</th><th style="width:100px">类型</th><th>内容</th><th style="width:150px">指标</th><th style="width:130px">推送</th><th style="width:50px"></th></tr></thead>
          <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td class="small mono"><?= e(date('Y-m-d H:i:s', strtotime($row['created_at']))) ?></td>
              <td><span class="badge <?= e($levelBadge[$row['level']] ?? 'badge--muted') ?>"><?= e($levelText[$row['level']] ?? $row['level']) ?></span></td>
              <td class="small"><?= e($kinds[$row['kind']] ?? $row['kind']) ?></td>
              <td>
                <div class="cell-main small"><?= e($row['title']) ?></div>
                <div class="cell-sub" style="white-space:pre-wrap"><?= e(mb_strimwidth((string)$row['message'], 0, 160, '…')) ?></div>
              </td>
              <td class="small mono">
                <?php if ($row['metric'] !== ''): ?>
                  <?= e($row['metric']) ?><br>
                  <span class="muted"><?= e(rtrim(rtrim(number_format((float)$row['value'], 2), '0'), '.')) ?> / 阈值 <?= e(rtrim(rtrim(number_format((float)$row['threshold'], 2), '0'), '.')) ?></span>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <td class="small">
                <?php if ((int)$row['sent'] === 1): ?>
                  <span class="badge badge--ok">已推送</span>
                  <?php if ($row['channel_result'] !== ''): ?>
                    <div class="cell-sub mono"><?= e($row['channel_result']) ?></div>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="badge badge--muted">仅记录</span>
                <?php endif; ?>
              </td>
              <td class="td-actions">
                <form method="post" action="<?= url('/alerts/' . (int)$row['id'] . '/delete') ?>" data-confirm="删除这条告警记录？">
                  <?= csrf_field() ?>
                  <button class="icon-btn icon-btn--danger" type="submit" title="删除"><?= icon('trash', 13) ?></button>
                </form>
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
      <span class="hint">第 <?= (int)$page ?> / <?= (int)$pages ?> 页</span>
      <div class="toolbar">
        <?php if ($page > 1): ?>
          <a class="btn btn--ghost btn--sm" href="<?= url('/alerts?' . http_build_query(array_filter(['kind' => $kind, 'level' => $level, 'from' => $from, 'to' => $to, 'page' => $page - 1]))) ?>">上一页</a>
        <?php endif; ?>
        <?php if ($page < $pages): ?>
          <a class="btn btn--ghost btn--sm" href="<?= url('/alerts?' . http_build_query(array_filter(['kind' => $kind, 'level' => $level, 'from' => $from, 'to' => $to, 'page' => $page + 1]))) ?>">下一页</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
