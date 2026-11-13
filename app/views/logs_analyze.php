<?php
declare(strict_types=1);
$classMeta = [
    '2xx' => ['成功', 'bar__fill--ok'],
    '3xx' => ['重定向', ''],
    '4xx' => ['客户端错误', 'bar__fill--warn'],
    '5xx' => ['服务端错误', 'bar__fill--danger'],
];
$totalReq = (int)($stats['requests'] ?? 0);
?>
<div class="section-head">
  <div>
    <h2>访问日志分析</h2>
    <p>直接解析 Nginx 访问日志（combined 格式），统计 PV / UV、状态码、Top URL、来源与蜘蛛。</p>
  </div>
  <div class="toolbar">
    <a class="btn btn--ghost btn--sm" href="<?= url('/logs') ?>"><?= icon('list', 14) ?>操作日志</a>
    <?php if ($source): ?>
      <a class="btn btn--ghost btn--sm" href="<?= url('/logs/analyze?' . http_build_query(['file' => $source['key'], 'range' => $range, 'mb' => $mb])) ?>"><?= icon('refresh', 14) ?>重新分析</a>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <span class="card__title"><?= icon('search', 17) ?>选择数据源</span>
    <?php if ($source): ?>
      <span class="badge badge--muted"><?= bytes($source['size']) ?> · <?= $source['mtime'] ? e(date('m-d H:i', $source['mtime'])) : '—' ?></span>
    <?php endif; ?>
  </div>
  <div class="card__body">
    <?php if (!$sources): ?>
      <div class="alert alert--warning">
        <?= icon('alert', 16) ?>
        <span>没有找到可分析的访问日志。请先在「网站」创建站点并产生访问，或确认托管 Nginx 已启动（日志位于 <code class="mono">storage/soft/nginx/logs/</code>）。</span>
      </div>
    <?php else: ?>
      <form method="get" action="<?= url('/logs/analyze') ?>">
        <div class="form-grid">
          <div class="field">
            <label>日志文件</label>
            <select class="input" name="file">
              <?php foreach ($sources as $item): ?>
                <option value="<?= e($item['key']) ?>" <?= $source && $source['key'] === $item['key'] ? 'selected' : '' ?>>
                  <?= e($item['label']) ?> · <?= bytes($item['size']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>时间范围</label>
            <select class="input" name="range">
              <?php foreach (['all' => '全部（按读取尾部）', '1h' => '最近 1 小时', '24h' => '最近 24 小时', '7d' => '最近 7 天'] as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $range === $value ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>读取尾部大小（MB）</label>
            <input class="input mono" type="number" name="mb" min="1" max="64" value="<?= (int)$mb ?>">
            <span class="hint">只解析文件最后这么多字节，避免大日志拖慢面板</span>
          </div>
        </div>
        <div class="toolbar mt-12">
          <button class="btn btn--primary btn--sm" type="submit"><?= icon('activity', 14) ?>开始分析</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($source && $stats): ?>
<?php if ($totalReq === 0): ?>
  <div class="alert alert--info mt-18">
    <?= icon('alert', 16) ?>
    <span>读取了 <?= (int)$stats['lines'] ?> 行日志，但当前筛选条件下没有可统计的请求。可能是时间范围过窄或日志格式非 combined。</span>
  </div>
<?php else: ?>

<section class="stats mt-18">
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon"><?= icon('activity', 16) ?></span>请求数</span><span class="badge badge--muted">PV</span></div>
    <div class="stat__value"><?= number_format($totalReq) ?></div>
    <div class="stat__meta"><span>解析行 / 总数</span><span><?= number_format((int)$stats['parsed']) ?> / <?= number_format((int)$stats['lines']) ?></span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--violet"><?= icon('user', 16) ?></span>独立 IP</span><span class="badge badge--muted">UV</span></div>
    <div class="stat__value"><?= number_format((int)$stats['uv']) ?></div>
    <div class="stat__meta"><span>人均请求</span><span><?= $stats['uv'] > 0 ? number_format($totalReq / $stats['uv'], 1) : '—' ?></span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--ok"><?= icon('download', 16) ?></span>出站流量</span><span class="badge badge--muted">响应体</span></div>
    <div class="stat__value" style="font-size:21px"><?= bytes((int)$stats['bytes']) ?></div>
    <div class="stat__meta"><span>平均每请求</span><span><?= bytes((int)$stats['avg_size']) ?></span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--warn"><?= icon('alert', 16) ?></span>错误率</span><span class="badge <?= $stats['error_rate'] > 5 ? 'badge--warn' : 'badge--muted' ?>"><?= number_format((float)$stats['error_rate'], 2) ?>%</span></div>
    <div class="stat__value" style="font-size:21px"><?= number_format((int)$stats['status']['4xx'] + (int)$stats['status']['5xx']) ?></div>
    <div class="stat__meta"><span>4xx / 5xx</span><span><?= number_format((int)$stats['status']['4xx']) ?> / <?= number_format((int)$stats['status']['5xx']) ?></span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--warn"><?= icon('box', 16) ?></span>蜘蛛占比</span><span class="badge badge--muted"><?= number_format((float)$stats['bot_rate'], 1) ?>%</span></div>
    <div class="stat__value" style="font-size:21px"><?= number_format((int)$stats['ua_bots']) ?></div>
    <div class="stat__meta"><span>来源种类</span><span><?= count($stats['top_spiders']) ?></span></div>
  </article>
</section>

<section class="grid grid--2 mt-18">
  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('network', 17) ?>状态码分布</span>
      <span class="badge badge--muted"><?= count($stats['top_codes']) ?> 种</span>
    </div>
    <div class="card__body">
      <?php foreach ($classMeta as $class => [$label, $fill]): ?>
        <?php $count = (int)($stats['status'][$class] ?? 0); $pct = $totalReq > 0 ? $count / $totalReq * 100 : 0; ?>
        <div style="margin-bottom:12px">
          <div class="toolbar" style="justify-content:space-between;margin-bottom:4px">
            <span class="small"><strong class="mono"><?= e($class) ?></strong> <?= e($label) ?></span>
            <span class="small muted mono"><?= number_format($count) ?> · <?= number_format($pct, 1) ?>%</span>
          </div>
          <div class="bar"><div class="bar__fill <?= $fill ?>" style="width:<?= number_format($pct, 1) ?>%"></div></div>
        </div>
      <?php endforeach; ?>
      <div class="toolbar" style="flex-wrap:wrap;gap:6px;margin-top:14px">
        <?php foreach ($stats['top_codes'] as $code): ?>
          <span class="badge <?= (int)$code['name'] >= 500 ? 'badge--danger' : ((int)$code['name'] >= 400 ? 'badge--warn' : 'badge--info') ?>">
            <span class="mono"><?= e($code['name']) ?></span> · <?= number_format($code['count']) ?>
          </span>
        <?php endforeach; ?>
      </div>
    </div>
  </article>

  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('clock', 17) ?>按小时请求趋势</span>
      <span class="badge badge--muted">最近 <?= count($stats['hour_list']) ?> 个整点</span>
    </div>
    <div class="card__body">
      <?php if (!$stats['hour_list']): ?>
        <div class="empty">无数据</div>
      <?php else: ?>
        <div style="display:flex;align-items:flex-end;gap:6px;height:150px;padding-top:8px">
          <?php foreach ($stats['hour_list'] as $hour): ?>
            <?php $h = $stats['max_hour'] > 0 ? $hour['count'] / $stats['max_hour'] * 100 : 0; ?>
            <div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;height:100%" title="<?= e($hour['label']) ?>：<?= number_format($hour['count']) ?> 次">
              <div style="width:100%;min-height:2px;height:<?= number_format(max(1.5, $h), 1) ?>%;background:linear-gradient(180deg,var(--chart-1),rgba(217,110,78,.35));border-radius:3px 3px 0 0"></div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="toolbar" style="justify-content:space-between;margin-top:6px">
          <span class="hint mono"><?= e($stats['hour_list'][0]['label']) ?></span>
          <span class="hint mono"><?= e($stats['hour_list'][count($stats['hour_list']) - 1]['label']) ?></span>
        </div>
      <?php endif; ?>
      <?php if ((int)$stats['unparsed'] > 0): ?>
        <p class="hint mt-12">有 <?= number_format((int)$stats['unparsed']) ?> 行不符合 combined 格式，已跳过。</p>
      <?php endif; ?>
    </div>
  </article>
</section>

<section class="grid grid--2 mt-18">
  <article class="card">
    <div class="card__head"><span class="card__title"><?= icon('globe', 17) ?>Top URL</span><span class="badge badge--muted">前 15</span></div>
    <div class="card__body card__body--flush">
      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th>路径</th><th style="width:90px">请求</th><th style="width:80px">占比</th></tr></thead>
          <tbody>
          <?php foreach ($stats['top_paths'] as $row): ?>
            <tr>
              <td class="cell-main mono small" style="word-break:break-all"><?= e($row['name']) ?></td>
              <td class="mono small"><?= number_format($row['count']) ?></td>
              <td class="small muted"><?= number_format($row['count'] / max(1, $totalReq) * 100, 1) ?>%</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </article>

  <article class="card">
    <div class="card__head"><span class="card__title"><?= icon('cpu', 17) ?>Top 客户端 IP</span><span class="badge badge--muted">前 10</span></div>
    <div class="card__body card__body--flush">
      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th>IP</th><th style="width:90px">请求</th><th style="width:80px">占比</th></tr></thead>
          <tbody>
          <?php foreach ($stats['top_ips'] as $row): ?>
            <tr>
              <td class="cell-main mono small"><?= e($row['name']) ?></td>
              <td class="mono small"><?= number_format($row['count']) ?></td>
              <td class="small muted"><?= number_format($row['count'] / max(1, $totalReq) * 100, 1) ?>%</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </article>
</section>

<section class="grid grid--2 mt-18">
  <article class="card">
    <div class="card__head"><span class="card__title"><?= icon('external', 17) ?>来源站点（Referer）</span><span class="badge badge--muted">前 10</span></div>
    <div class="card__body card__body--flush">
      <?php if (!$stats['top_referers']): ?>
        <div class="empty">没有外部来源（直接访问或未带 Referer）</div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th>来源域名</th><th style="width:90px">请求</th></tr></thead>
            <tbody>
            <?php foreach ($stats['top_referers'] as $row): ?>
              <tr>
                <td class="cell-main mono small"><?= e($row['name']) ?></td>
                <td class="mono small"><?= number_format($row['count']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </article>

  <article class="card">
    <div class="card__head"><span class="card__title"><?= icon('box', 17) ?>蜘蛛统计</span><span class="badge badge--muted"><?= number_format((int)$stats['ua_bots']) ?> 次</span></div>
    <div class="card__body card__body--flush">
      <?php if (!$stats['top_spiders']): ?>
        <div class="empty">未识别到搜索引擎蜘蛛</div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th>蜘蛛</th><th style="width:90px">抓取次数</th><th style="width:80px">占比</th></tr></thead>
            <tbody>
            <?php foreach ($stats['top_spiders'] as $row): ?>
              <tr>
                <td class="cell-main"><?= e($row['name']) ?></td>
                <td class="mono small"><?= number_format($row['count']) ?></td>
                <td class="small muted"><?= number_format($row['count'] / max(1, $totalReq) * 100, 1) ?>%</td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </article>
</section>

<?php if ($stats['top_errors']): ?>
<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('alert', 17) ?>错误请求 Top 10</span>
    <span class="badge badge--warn">4xx / 5xx</span>
  </div>
  <div class="card__body card__body--flush">
    <div class="table-wrap">
      <table class="table table--compact">
        <thead><tr><th style="width:80px">状态</th><th>路径</th><th style="width:90px">次数</th></tr></thead>
        <tbody>
        <?php foreach ($stats['top_errors'] as $row): ?>
          <?php $parts = explode(' ', $row['name'], 2); ?>
          <tr>
            <td><span class="badge <?= (int)$parts[0] >= 500 ? 'badge--danger' : 'badge--warn' ?> mono"><?= e($parts[0]) ?></span></td>
            <td class="mono small" style="word-break:break-all"><?= e($parts[1] ?? '') ?></td>
            <td class="mono small"><?= number_format($row['count']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($source): ?>
<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('terminal', 17) ?>实时追踪<span class="card__desc">每 3 秒增量拉取，只追加新行</span></span>
    <div class="toolbar">
      <label class="badge badge--muted" style="cursor:pointer"><input type="checkbox" id="tailFollow" checked style="margin-right:6px">自动滚动</label>
      <button class="btn btn--ghost btn--sm" type="button" id="tailClear"><?= icon('trash', 13) ?>清屏</button>
    </div>
  </div>
  <div class="card__body" style="padding:0">
    <pre id="logTail" data-file="<?= e($source['key']) ?>" data-offset="<?= max(0, (int)$source['size'] - 8192) ?>" style="margin:0;padding:14px 16px;max-height:340px;overflow:auto;font-family:ui-monospace,Consolas,monospace;font-size:12px;line-height:1.55;background:#0f1421;color:#c7d2fe;border-radius:0 0 12px 12px;white-space:pre-wrap;word-break:break-all"></pre>
  </div>
</section>
<script>
(function () {
  var pre = document.getElementById('logTail');
  if (!pre) { return; }
  var file = pre.getAttribute('data-file');
  var offset = parseInt(pre.getAttribute('data-offset'), 10) || 0;
  var follow = document.getElementById('tailFollow');
  var clearBtn = document.getElementById('tailClear');
  if (clearBtn) { clearBtn.addEventListener('click', function () { pre.textContent = ''; }); }
  var endpoint = <?= json_encode(url('/logs/tail')) ?>;
  function tick() {
    fetch(endpoint + '?file=' + encodeURIComponent(file) + '&offset=' + offset, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { return; }
        if (d.rotated) { pre.textContent = ''; }
        if (d.lines && d.lines.length) {
          pre.textContent += d.lines.join('\n') + '\n';
          if (pre.textContent.length > 524288) { pre.textContent = pre.textContent.slice(-524288); }
          if (!follow || follow.checked) { pre.scrollTop = pre.scrollHeight; }
        }
        offset = d.offset;
      })
      .catch(function () {})
      .then(function () { setTimeout(tick, 3000); });
  }
  tick();
})();
</script>
<?php endif; ?>

<p class="hint mt-18">
  统计区间：<?= $stats['first_time'] ? e(date('Y-m-d H:i:s', (int)$stats['first_time'])) : '—' ?> ~ <?= $stats['last_time'] ? e(date('Y-m-d H:i:s', (int)$stats['last_time'])) : '—' ?>
  · 数据源 <span class="mono"><?= e($source['path']) ?></span>
</p>

<?php endif; ?>
<?php endif; ?>
