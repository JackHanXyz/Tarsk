<?php
declare(strict_types=1);
$s = $status['status'] ?? [];
$connUsage = (float)($s['conn_usage'] ?? 0);
?>
<div class="section-head">
  <div>
    <h2>MySQL 状态</h2>
    <p>实时读取 <code class="mono">SHOW GLOBAL STATUS / VARIABLES</code>，并支持在线调整常用参数与终止异常连接。</p>
  </div>
  <div class="toolbar">
    <span class="badge <?= !empty($s) ? 'badge--ok' : 'badge--danger' ?>"><i></i><?= !empty($s) ? 'MySQL ' . e($s['version']) : '未连接' ?></span>
    <a class="btn btn--ghost btn--sm" href="<?= url('/env/mysql') ?>"><?= icon('refresh', 14) ?>刷新</a>
  </div>
</div>

<?php if (empty($s)): ?>
  <div class="alert alert--error">
    <?= icon('alert', 16) ?>
    <span><?= e($status['message'] ?? '无法连接 MySQL') ?>。可在<a href="<?= url('/settings') ?>">面板设置</a>中更换管理账号。</span>
  </div>
<?php else: ?>

<section class="stats">
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon"><?= icon('activity', 16) ?></span>平均 QPS</span><span class="badge badge--muted"><?= e(uptime_text((int)$s['uptime'])) ?></span></div>
    <div class="stat__value"><?= number_format((float)$s['qps'], 2) ?></div>
    <div class="stat__meta"><span>累计查询</span><span><?= number_format((int)$s['queries']) ?></span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--violet"><?= icon('network', 16) ?></span>连接</span><span class="badge badge--muted"><?= number_format($connUsage, 1) ?>%</span></div>
    <div class="stat__value"><?= (int)$s['threads_connected'] ?><small>/ <?= (int)$s['max_connections'] ?></small></div>
    <div class="stat__meta"><span>历史峰值</span><span><?= (int)$s['max_used_connections'] ?></span></div>
    <div class="bar"><div class="bar__fill <?= $connUsage >= 80 ? 'bar__fill--danger' : ($connUsage >= 60 ? 'bar__fill--warn' : '') ?>" style="width:<?= number_format(min(100, $connUsage), 1) ?>%"></div></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--warn"><?= icon('clock', 16) ?></span>慢查询</span><span class="badge <?= (int)$s['slow_queries'] > 0 ? 'badge--warn' : 'badge--muted' ?>"><?= number_format((float)$s['slow_ratio'], 3) ?>%</span></div>
    <div class="stat__value"><?= number_format((int)$s['slow_queries']) ?></div>
    <div class="stat__meta"><span>中止连接</span><span><?= number_format((int)$s['aborted_connects']) ?></span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--ok"><?= icon('memory', 16) ?></span>Buffer Pool 命中</span><span class="badge badge--muted"><?= number_format((float)$s['buffer_pool_hit'], 2) ?>%</span></div>
    <div class="stat__value" style="font-size:20px"><?= bytes((int)$s['buffer_pool_size']) ?></div>
    <div class="stat__meta"><span>建议命中率</span><span>&gt; 99%</span></div>
  </article>
</section>

<?php if ((float)$s['buffer_pool_hit'] < 99 && (int)$s['buffer_pool_size'] > 0): ?>
  <div class="alert alert--warning mt-18">
    <?= icon('alert', 16) ?>
    <span>Buffer Pool 命中率偏低，说明热数据放不进内存。可在下方调大 <code class="mono">innodb_buffer_pool_size</code>（需重启 MySQL 才生效）。</span>
  </div>
<?php endif; ?>

<section class="grid grid--sidebar mt-18">
  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('settings', 17) ?>运行参数</span>
      <span class="badge badge--warn">立即生效，重启后恢复</span>
    </div>
    <div class="card__body card__body--flush">
      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th>参数</th><th>当前值</th><th>调整</th></tr></thead>
          <tbody>
          <?php foreach ($tunables as $t): ?>
            <tr>
              <td class="cell-main">
                <span class="mono small"><?= e($t['name']) ?></span>
                <div class="cell-sub"><?= e($t['label']) ?> · <?= e($t['hint']) ?></div>
              </td>
              <td class="mono small"><?= e($t['value'] !== '' ? $t['value'] : '—') ?></td>
              <td class="td-actions">
                <form method="post" action="<?= url('/env/mysql/variable') ?>" style="display:flex;gap:6px;align-items:center">
                  <?= csrf_field() ?>
                  <input type="hidden" name="name" value="<?= e($t['name']) ?>">
                  <input class="input mono" type="text" name="value" value="<?= e($t['value']) ?>" style="width:120px;padding:4px 8px;font-size:12px">
                  <button class="icon-btn" type="submit" title="应用"><?= icon('check', 14) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card__foot"><span class="hint">使用 SET GLOBAL 修改，需要账号具备 SUPER / SYSTEM_VARIABLES_ADMIN 权限；永久生效请写入 my.ini。</span></div>
  </article>

  <div class="stack">
    <article class="card">
      <div class="card__head"><span class="card__title"><?= icon('server', 17) ?>实例信息</span></div>
      <div class="card__body">
        <dl class="kv">
          <?php foreach ($details as $name => $value): ?>
            <dt class="mono small"><?= e($name) ?></dt><dd class="mono small"><?= e(mb_strimwidth((string)$value, 0, 46, '…')) ?></dd>
          <?php endforeach; ?>
        </dl>
      </div>
    </article>

    <article class="card mt-18">
      <div class="card__head"><span class="card__title"><?= icon('activity', 17) ?>其他计数</span></div>
      <div class="card__body">
        <dl class="kv">
          <dt>磁盘临时表</dt><dd><?= number_format((int)$s['created_tmp_disk_tables']) ?></dd>
          <dt>全表扫描 JOIN</dt><dd><?= number_format((int)$s['select_full_join']) ?></dd>
          <dt>表锁等待</dt><dd><?= number_format((int)$s['table_locks_waited']) ?></dd>
          <dt>累计连接</dt><dd><?= number_format((int)$s['connections_total']) ?></dd>
          <dt>接收 / 发送</dt><dd><?= bytes((int)$s['bytes_received']) ?> / <?= bytes((int)$s['bytes_sent']) ?></dd>
          <dt>运行时长</dt><dd><?= e(uptime_text((int)$s['uptime'])) ?></dd>
        </dl>
      </div>
    </article>
  </div>
</section>

<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('list', 17) ?>当前连接（SHOW PROCESSLIST）</span>
    <span class="badge badge--muted"><?= count($processes) ?></span>
  </div>
  <div class="card__body card__body--flush">
    <?php if (!$processes): ?>
      <div class="empty">没有可显示的连接</div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th>ID</th><th>用户</th><th>来源</th><th>库</th><th>状态</th><th>耗时</th><th>SQL</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($processes as $p): ?>
            <tr>
              <td class="mono small"><?= (int)$p['Id'] ?></td>
              <td class="small"><?= e((string)$p['User']) ?></td>
              <td class="mono small"><?= e((string)$p['Host']) ?></td>
              <td class="small mono"><?= e((string)($p['db'] ?? '')) ?></td>
              <td class="small"><?= e((string)$p['Command']) ?><?= !empty($p['State']) ? ' · ' . e(mb_strimwidth((string)$p['State'], 0, 18, '…')) : '' ?></td>
              <td class="small"><?= (int)$p['Time'] ?>s</td>
              <td class="mono small" style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e((string)($p['Info'] ?? '')) ?>"><?= e(mb_strimwidth((string)($p['Info'] ?? ''), 0, 60, '…')) ?></td>
              <td class="td-actions">
                <?php if ((string)$p['Command'] !== 'Daemon'): ?>
                  <form method="post" action="<?= url('/env/mysql/kill') ?>" data-confirm="终止连接 <?= (int)$p['Id'] ?> 会中断该查询，确认继续？">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$p['Id'] ?>">
                    <button class="icon-btn icon-btn--danger" type="submit" title="终止连接"><?= icon('x', 14) ?></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php endif; ?>
