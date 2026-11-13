<?php
declare(strict_types=1);
$dbTabs = [
    ['key' => 'list', 'label' => '数据库', 'url' => '/databases', 'icon' => 'database'],
    ['key' => 'users', 'label' => '用户与权限', 'url' => '/databases/users', 'icon' => 'user'],
    ['key' => 'slow', 'label' => '慢查询报表', 'url' => '/databases/slow', 'icon' => 'activity'],
    ['key' => 'replication', 'label' => '主从与同步', 'url' => '/databases/replication', 'icon' => 'network'],
];
$groups = $report['groups'] ?? [];
$totalTime = 0.0;
$totalCount = 0;
foreach ($groups as $group) {
    $totalTime += (float)$group['total_time'];
    $totalCount += (int)$group['count'];
}
$maxTime = 0.0;
foreach ($groups as $group) {
    $maxTime = max($maxTime, (float)$group['total_time']);
}
?>
<div class="section-head">
  <div>
    <h2>慢查询报表</h2>
    <p>解析 MySQL 慢查询日志，按 SQL 模板聚合同类语句，找出最耗时的查询。</p>
  </div>
  <div class="toolbar">
    <span class="badge <?= $slow_on ? 'badge--ok' : 'badge--muted' ?>"><i></i><?= $slow_on ? '慢查询日志已开启' : '慢查询日志未开启' ?></span>
    <a class="btn btn--ghost btn--sm" href="<?= url('/databases/slow?mb=' . $mb . '&limit=' . $limit) ?>"><?= icon('refresh', 14) ?>重新分析</a>
  </div>
</div>

<div class="toolbar" style="margin-bottom:14px;flex-wrap:wrap">
  <?php foreach ($dbTabs as $tab): ?>
    <a class="btn btn--sm <?= $tab['key'] === 'slow' ? 'btn--primary' : 'btn--ghost' ?>" href="<?= url($tab['url']) ?>"><?= icon($tab['icon'], 14) ?><?= e($tab['label']) ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$slow_on): ?>
  <div class="alert alert--warning">
    <?= icon('alert', 16) ?>
    <span>慢查询日志当前未开启，日志文件里不会有新记录。可在下方一键开启（立即生效，重启 MySQL 后恢复默认）。</span>
  </div>
<?php endif; ?>

<section class="grid grid--sidebar">
  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('settings', 17) ?>采集设置</span>
      <span class="badge badge--muted">阈值 <?= e($long_query_time !== '' ? $long_query_time . 's' : '—') ?></span>
    </div>
    <form method="post" action="<?= url('/databases/slow/save') ?>">
      <?= csrf_field() ?>
      <div class="card__body">
        <div class="form-grid">
          <div class="field">
            <label>慢查询日志</label>
            <select class="input" name="enable">
              <option value="1" <?= $slow_on ? 'selected' : '' ?>>开启</option>
              <option value="" <?= !$slow_on ? 'selected' : '' ?>>关闭</option>
            </select>
          </div>
          <div class="field">
            <label>慢查询阈值（秒）</label>
            <input class="input mono" type="number" name="long_query_time" min="0" max="600" step="0.1" value="<?= e($long_query_time !== '' ? $long_query_time : '2') ?>">
            <span class="hint">执行时间超过该值的 SQL 才会被记录</span>
          </div>
        </div>
        <p class="hint mt-12">
          日志文件：<span class="mono"><?= e($report['path'] ?: '未获取到路径') ?></span><br>
          当前读取尾部 <?= (int)$mb ?> MB，文件总大小 <?= bytes((int)($report['size'] ?? 0)) ?>。
          若 <code class="mono">log_output</code> 为 TABLE，日志会写进 <code class="mono">mysql.slow_log</code> 表而不是文件。
        </p>
      </div>
      <div class="card__foot" style="display:flex;justify-content:flex-end">
        <button class="btn btn--primary btn--sm" type="submit"><?= icon('save', 14) ?>应用并重新分析</button>
      </div>
    </form>
  </article>

  <article class="card">
    <div class="card__head"><span class="card__title"><?= icon('activity', 17) ?>本次读取</span></div>
    <div class="card__body">
      <dl class="kv">
        <dt>可解析条目</dt><dd><?= number_format((int)($report['total'] ?? 0)) ?></dd>
        <dt>SQL 模板数</dt><dd><?= number_format((int)($report['group_count'] ?? 0)) ?></dd>
        <dt>累计耗时</dt><dd><?= number_format($totalTime, 2) ?> s</dd>
        <dt>读取字节</dt><dd><?= bytes((int)($report['bytes'] ?? 0)) ?></dd>
        <dt>文件状态</dt><dd><?= !empty($report['exists']) ? '存在' : '不存在' ?></dd>
      </dl>
      <form method="get" action="<?= url('/databases/slow') ?>" class="mt-12">
        <div class="form-grid">
          <div class="field">
            <label>读取尾部（MB）</label>
            <input class="input mono" type="number" name="mb" min="1" max="64" value="<?= (int)$mb ?>">
          </div>
          <div class="field">
            <label>显示条数</label>
            <input class="input mono" type="number" name="limit" min="5" max="100" value="<?= (int)$limit ?>">
          </div>
        </div>
        <button class="btn btn--ghost btn--sm mt-12" type="submit"><?= icon('search', 14) ?>重新统计</button>
      </form>
    </div>
  </article>
</section>

<?php if (!$report['ok']): ?>
  <div class="alert alert--error mt-18">
    <?= icon('alert', 16) ?>
    <span><?= e($report['message']) ?></span>
  </div>
<?php elseif (!$groups): ?>
  <div class="card mt-18">
    <div class="card__body">
      <div class="empty">
        <?= icon('activity', 30) ?>
        <p><?= (int)$report['total'] > 0 ? '日志里有记录，但没能解析出 SQL 本体' : '当前日志里还没有慢查询记录' ?></p>
        <small>开启慢查询日志并产生超过阈值的查询后，再回来刷新</small>
      </div>
    </div>
  </div>
<?php else: ?>

<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('list', 17) ?>按总耗时排序（Top <?= count($groups) ?>）</span>
    <span class="badge badge--muted">共 <?= number_format($totalCount) ?> 次慢查询</span>
  </div>
  <div class="card__body card__body--flush">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>SQL 模板</th>
            <th style="width:80px">次数</th>
            <th style="width:110px">总耗时</th>
            <th style="width:110px">平均</th>
            <th style="width:100px">最大</th>
            <th style="width:110px">平均扫描行</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($groups as $group): ?>
          <?php $pct = $maxTime > 0 ? (float)$group['total_time'] / $maxTime * 100 : 0; ?>
          <tr>
            <td>
              <div class="mono small" style="word-break:break-all;max-width:560px"><?= e(mb_strimwidth($group['template'], 0, 200, '…')) ?></div>
              <details>
                <summary class="cell-sub" style="cursor:pointer">完整语句示例</summary>
                <pre class="pre pre--light mt-12" style="max-height:220px;overflow:auto"><?= e($group['sample']) ?></pre>
              </details>
              <div class="bar mt-12" style="max-width:320px"><div class="bar__fill <?= $pct > 60 ? 'bar__fill--danger' : ($pct > 30 ? 'bar__fill--warn' : '') ?>" style="width:<?= number_format($pct, 1) ?>%"></div></div>
            </td>
            <td class="mono small"><?= number_format((int)$group['count']) ?></td>
            <td class="mono small"><?= number_format((float)$group['total_time'], 2) ?>s</td>
            <td class="mono small"><?= number_format((float)$group['avg_time'], 3) ?>s</td>
            <td class="mono small"><?= number_format((float)$group['max_time'], 3) ?>s</td>
            <td class="mono small"><?= number_format((int)$group['avg_rows']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card__foot">
    <span class="small muted">模板中的字面量已替换为 <code class="mono">?</code>，同类语句会合并统计。</span>
  </div>
</section>

<?php endif; ?>
