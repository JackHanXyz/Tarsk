<?php
declare(strict_types=1);

$levelText = ['info' => '信息', 'warning' => '警告', 'danger' => '危险'];
?>
<div class="section-head">
  <div>
    <h2>操作日志</h2>
    <p>面板内的关键操作都会记录，默认保留 90 天。</p>
  </div>
  <div class="toolbar">
    <span class="badge badge--muted">共 <?= number_format($total) ?> 条</span>
    <a class="btn btn--ghost btn--sm" href="<?= url('/logs/export?' . http_build_query(array_filter(['q' => $keyword, 'level' => $level, 'username' => $username, 'from' => $from, 'to' => $to]))) ?>"><?= icon('download', 14) ?>导出 CSV</a>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <span class="card__title"><?= icon('list', 17) ?>日志记录</span>
    <form class="toolbar" method="get" action="<?= url('/logs') ?>">
      <div class="search-box">
        <?= icon('search', 14) ?>
        <input type="search" name="q" value="<?= e($keyword) ?>" placeholder="搜索操作、详情、账号或 IP">
      </div>
      <select class="input" name="level" style="height:32px;width:auto">
        <option value="">全部级别</option>
        <option value="info" <?= $level === 'info' ? 'selected' : '' ?>>信息</option>
        <option value="warning" <?= $level === 'warning' ? 'selected' : '' ?>>警告</option>
        <option value="danger" <?= $level === 'danger' ? 'selected' : '' ?>>危险</option>
      </select>
      <input class="input" type="text" name="username" value="<?= e($username) ?>" placeholder="账号" style="height:32px;width:110px">
      <input class="input" type="date" name="from" value="<?= e($from) ?>" style="height:32px;width:auto" title="起始日期">
      <input class="input" type="date" name="to" value="<?= e($to) ?>" style="height:32px;width:auto" title="结束日期">
      <button class="btn btn--ghost btn--sm" type="submit">筛选</button>
      <a class="btn btn--ghost btn--sm" href="<?= url('/logs') ?>">重置</a>
    </form>
  </div>
  <div class="card__body card__body--flush">
    <?php if (!$logs): ?>
      <div class="empty">
        <?= icon('list', 30) ?>
        <p>没有匹配的日志</p>
        <small>调整筛选条件或执行一些操作后再查看</small>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th style="width:150px">时间</th><th>级别</th><th>操作</th><th>详情</th><th>账号</th><th>IP</th></tr></thead>
          <tbody>
          <?php foreach ($logs as $log): ?>
            <tr>
              <td class="small mono"><?= e(date('Y-m-d H:i:s', strtotime($log['created_at']))) ?></td>
              <td>
                <span class="badge <?= $log['level'] === 'danger' ? 'badge--danger' : ($log['level'] === 'warning' ? 'badge--warn' : 'badge--info') ?>">
                  <?= e($levelText[$log['level']] ?? $log['level']) ?>
                </span>
              </td>
              <td class="cell-main"><?= e($log['action']) ?></td>
              <td class="small"><?= e($log['detail'] ?: '—') ?></td>
              <td class="small"><?= e($log['username'] ?: '—') ?></td>
              <td class="small mono muted"><?= e($log['ip']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  <?php if ($pages > 1): ?>
    <div class="card__foot">
      <div class="toolbar" style="justify-content:space-between">
        <span class="small muted">第 <?= $page ?> / <?= $pages ?> 页</span>
        <div class="toolbar">
          <?php
          $query = function (int $p) use ($keyword, $level, $username, $from, $to) {
              $params = array_filter(['q' => $keyword, 'level' => $level, 'username' => $username, 'from' => $from, 'to' => $to]);
              $params['page'] = $p;
              return url('/logs?' . http_build_query($params));
          };
          ?>
          <a class="btn btn--ghost btn--sm <?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= $page <= 1 ? '#' : $query($page - 1) ?>" <?= $page <= 1 ? 'aria-disabled="true"' : '' ?>>上一页</a>
          <a class="btn btn--ghost btn--sm" href="<?= $query(min($pages, $page + 1)) ?>" <?= $page >= $pages ? 'aria-disabled="true"' : '' ?>>下一页</a>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>
