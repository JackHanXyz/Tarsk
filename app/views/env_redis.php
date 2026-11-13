<?php
declare(strict_types=1);
$usedPct = ($memory['maxmemory'] > 0) ? min(100, $memory['used'] / $memory['maxmemory'] * 100) : 0;
$hitTotal = $stats['hits'] + $stats['misses'];
$hitRate = $hitTotal > 0 ? round($stats['hits'] / $hitTotal * 100, 2) : 0.0;
$ttlText = static function (int $ttl): string {
    if ($ttl === -1) {
        return '永久';
    }
    if ($ttl === -2) {
        return '已失效';
    }
    return $ttl >= 3600 ? floor($ttl / 3600) . 'h' : ($ttl >= 60 ? floor($ttl / 60) . 'm' : $ttl . 's');
};
?>
<div class="section-head">
  <div>
    <h2>Redis 管理</h2>
    <p>查看内存与命中率、按模式扫描键、清理无用数据。全部通过原生 RESP 协议直连，不依赖 phpredis 扩展。</p>
  </div>
  <div class="toolbar">
    <span class="badge <?= $target['running'] ? 'badge--ok' : ($info['ok'] ? 'badge--warn' : 'badge--danger') ?>"><i></i><?= $info['ok'] ? '已连接 127.0.0.1:' . (int)$target['port'] : '未连接' ?></span>
    <a class="btn btn--ghost btn--sm" href="<?= url('/env/redis?pattern=' . urlencode($pattern)) ?>"><?= icon('refresh', 14) ?>刷新</a>
  </div>
</div>

<?php if (!$info['ok']): ?>
  <div class="alert alert--error">
    <?= icon('alert', 16) ?>
    <span><?= e($info['message']) ?>。请先在<a href="<?= url('/store') ?>">软件商店</a>安装并启动 Redis（默认端口 6380）。</span>
  </div>
<?php else: ?>

<section class="stats">
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon"><?= icon('memory', 16) ?></span>已用内存</span><span class="badge badge--muted"><?= e($memory['policy'] ?: 'noeviction') ?></span></div>
    <div class="stat__value"><?= bytes($memory['used']) ?></div>
    <div class="stat__meta"><span>峰值 / RSS</span><span><?= bytes($memory['peak']) ?> / <?= bytes($memory['rss']) ?></span></div>
    <?php if ($memory['maxmemory'] > 0): ?>
      <div class="bar"><div class="bar__fill <?= $usedPct >= 85 ? 'bar__fill--danger' : ($usedPct >= 70 ? 'bar__fill--warn' : '') ?>" style="width:<?= number_format($usedPct, 1) ?>%"></div></div>
    <?php else: ?>
      <div class="stat__meta"><span>maxmemory</span><span>未限制</span></div>
    <?php endif; ?>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--ok"><?= icon('zap', 16) ?></span>命中率</span><span class="badge badge--muted"><?= e((string)($memory['fragmentation'] ?: '—')) ?> frag</span></div>
    <div class="stat__value"><?= number_format($hitRate, 2) ?><small>%</small></div>
    <div class="stat__meta"><span>命中 / 未命中</span><span><?= number_format($stats['hits']) ?> / <?= number_format($stats['misses']) ?></span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--violet"><?= icon('activity', 16) ?></span>实时 OPS</span><span class="badge badge--muted"><?= e($stats['version'] ? 'v' . $stats['version'] : '') ?></span></div>
    <div class="stat__value"><?= number_format($stats['ops']) ?></div>
    <div class="stat__meta"><span>累计命令</span><span><?= number_format($stats['total_commands']) ?></span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--warn"><?= icon('network', 16) ?></span>客户端 / 键数</span><span class="badge badge--muted"><?= e(uptime_text($stats['uptime'])) ?></span></div>
    <div class="stat__value"><?= number_format($stats['clients']) ?><small> / <?= $dbsize === null ? '—' : number_format($dbsize) ?></small></div>
    <div class="stat__meta"><span>淘汰 / 过期</span><span><?= number_format($stats['evicted']) ?> / <?= number_format($stats['expired']) ?></span></div>
  </article>
</section>

<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('search', 17) ?>键浏览</span>
    <span class="badge badge--muted">SCAN 非阻塞遍历</span>
  </div>
  <div class="card__body">
    <form method="get" action="<?= url('/env/redis') ?>">
      <div class="form-grid">
        <div class="field">
          <label>匹配模式</label>
          <input class="input mono" type="text" name="pattern" value="<?= e($pattern) ?>" placeholder="user:*  或  *">
          <span class="hint">支持 Redis glob：* ? [abc]</span>
        </div>
        <div class="field">
          <label>最多返回</label>
          <input class="input mono" type="number" name="limit" min="10" max="500" value="<?= (int)$limit ?>">
          <span class="hint">单次最多 500 个键，避免拖慢实例</span>
        </div>
      </div>
      <div class="toolbar mt-12">
        <button class="btn btn--primary btn--sm" type="submit"><?= icon('search', 14) ?>扫描</button>
      </div>
    </form>
    <form method="post" action="<?= url('/env/redis/flush') ?>" data-confirm="将清空当前 Redis 数据库的全部键，不可恢复，确认继续？" class="mt-12">
      <?= csrf_field() ?>
      <button class="btn btn--danger-ghost btn--sm" type="submit"><?= icon('trash', 14) ?>清空当前库</button>
    </form>
  </div>
</section>

<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('list', 17) ?>扫描结果</span>
    <span class="badge badge--muted"><?= (int)$scan['total'] ?> 个键<?= $dbsize !== null ? ' / 库共 ' . number_format($dbsize) : '' ?></span>
  </div>
  <?php if (!$scan['ok']): ?>
    <div class="card__body"><div class="alert alert--error"><?= icon('alert', 16) ?><span><?= e($scan['message']) ?></span></div></div>
  <?php elseif (!$scan['keys']): ?>
    <div class="card__body"><div class="empty">没有匹配 “<?= e($pattern) ?>” 的键</div></div>
  <?php else: ?>
    <form method="post" action="<?= url('/env/redis/delete') ?>" data-confirm="将删除选中的键，不可恢复，确认继续？">
      <?= csrf_field() ?>
      <input type="hidden" name="pattern" value="<?= e($pattern) ?>">
      <div class="card__body card__body--flush">
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th style="width:36px"><input type="checkbox" data-check-all></th><th>键</th><th>类型</th><th>TTL</th><th>内存</th></tr></thead>
            <tbody>
            <?php foreach ($scan['keys'] as $row): ?>
              <tr>
                <td><input type="checkbox" name="keys[]" value="<?= e($row['key']) ?>"></td>
                <td class="cell-main mono small" style="word-break:break-all"><?= e(mb_strimwidth($row['key'], 0, 96, '…')) ?></td>
                <td><span class="badge badge--muted"><?= e($row['type']) ?></span></td>
                <td class="small"><?= e($ttlText((int)$row['ttl'])) ?></td>
                <td class="small"><?= $row['size'] > 0 ? bytes((int)$row['size']) : '—' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="card__foot" style="display:flex;justify-content:space-between;align-items:center">
        <span class="hint">删除操作会记录到操作日志。</span>
        <button class="btn btn--danger btn--sm" type="submit"><?= icon('trash', 14) ?>删除选中键</button>
      </div>
    </form>
  <?php endif; ?>
</section>

<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('terminal', 17) ?>INFO 原始信息</span>
    <span class="badge badge--muted"><?= count($sections) ?> 个分区</span>
  </div>
  <div class="card__body">
    <?php foreach ($sections as $section => $items): ?>
      <details class="mt-12">
        <summary class="mono small" style="cursor:pointer"># <?= e($section) ?>（<?= count($items) ?>）</summary>
        <pre class="pre pre--light mt-12"><?php
          $lines = [];
          foreach ($items as $k => $v) {
              $lines[] = $k . ':' . $v;
          }
          echo e(implode("\n", $lines));
        ?></pre>
      </details>
    <?php endforeach; ?>
  </div>
</section>

<?php endif; ?>
