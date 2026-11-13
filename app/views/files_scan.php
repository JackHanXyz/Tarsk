<?php
declare(strict_types=1);
$counts = $result['counts'] ?? [];
$hits = $result['hits'] ?? [];
$isCompare = ($result['kind'] ?? '') === 'compare';
$isScan = ($result['kind'] ?? '') === 'scan';
$highCount = (int)($result['high'] ?? 0);
$mediumCount = (int)($result['medium'] ?? 0);
?>
<div class="section-head">
  <div>
    <h2>文件安全</h2>
    <p>为站点目录建立哈希基线，检测文件被篡改；再用特征规则扫描 Webshell / 木马代码。全程只读，不改动业务文件。</p>
  </div>
  <div class="toolbar">
    <a class="btn btn--ghost btn--sm" href="<?= url('/files') ?>"><?= icon('folder', 14) ?>文件管理</a>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <span class="card__title"><?= icon('shield', 17) ?>选择站点</span>
    <span class="badge badge--muted"><?= count($rules) ?> 条检测规则</span>
  </div>
  <div class="card__body">
    <?php if (!$sites): ?>
      <div class="empty">
        <?= icon('shield', 30) ?>
        <p>还没有站点</p>
        <small>先创建站点并上传文件，再回来做安全检查</small>
      </div>
    <?php else: ?>
      <form method="get" action="<?= url('/files/scan') ?>" class="toolbar" style="flex-wrap:wrap">
        <select class="input" name="site" style="width:auto;min-width:220px" onchange="this.form.submit()">
          <?php foreach ($sites as $item): ?>
            <option value="<?= e($item['name']) ?>" <?= $site && $site['name'] === $item['name'] ? 'selected' : '' ?>>
              <?= e($item['name']) ?><?= $item['domain'] !== '' ? ' · ' . e($item['domain']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn--ghost btn--sm" type="submit">切换</button>
      </form>

      <?php if ($site): ?>
        <div class="toolbar mt-12" style="flex-wrap:wrap">
          <form method="post" action="<?= url('/files/scan/baseline') ?>" data-confirm="将用当前目录内容覆盖已有基线（哈希清单），确认继续？" data-busy="正在计算文件哈希…">
            <?= csrf_field() ?>
            <input type="hidden" name="site" value="<?= e($site['name']) ?>">
            <button class="btn btn--primary btn--sm" type="submit"><?= icon('save', 14) ?><?= $baseline ? '重建基线' : '建立基线' ?></button>
          </form>
          <form method="post" action="<?= url('/files/scan/compare') ?>" data-busy="正在比对文件…">
            <?= csrf_field() ?>
            <input type="hidden" name="site" value="<?= e($site['name']) ?>">
            <button class="btn btn--ghost btn--sm" type="submit" <?= $baseline ? '' : 'disabled' ?>><?= icon('search', 14) ?>检查篡改</button>
          </form>
          <form method="post" action="<?= url('/files/scan/run') ?>" data-busy="正在扫描可疑代码…">
            <?= csrf_field() ?>
            <input type="hidden" name="site" value="<?= e($site['name']) ?>">
            <button class="btn btn--danger-ghost btn--sm" type="submit"><?= icon('alert', 14) ?>扫描 Webshell</button>
          </form>
        </div>
        <p class="hint mt-12">
          站点目录：<span class="mono"><?= e($site['path']) ?></span>
          <?php if ($baseline): ?>
            · 基线建立于 <?= e($baseline['created_at']) ?>，共 <?= number_format($baseline['file_count']) ?> 个文件
          <?php else: ?>
            · 尚未建立基线（建立后才能做篡改比对）
          <?php endif; ?>
        </p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($isCompare): ?>
  <section class="stats mt-18" style="grid-template-columns:repeat(4,minmax(0,1fr))">
    <article class="stat">
      <div class="stat__head"><span class="stat__label"><span class="stat__icon"><?= icon('folder', 16) ?></span>当前文件数</span></div>
      <div class="stat__value"><?= number_format((int)($result['total'] ?? 0)) ?></div>
      <div class="stat__meta"><span>基线时间</span><span><?= e((string)($result['baseline_at'] ?? '')) ?></span></div>
    </article>
    <article class="stat">
      <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--warn"><?= icon('plus', 16) ?></span>新增</span></div>
      <div class="stat__value"><?= number_format((int)($counts['added'] ?? 0)) ?></div>
      <div class="stat__meta"><span>基线里没有的文件</span></div>
    </article>
    <article class="stat">
      <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--warn"><?= icon('edit', 16) ?></span>修改</span></div>
      <div class="stat__value"><?= number_format((int)($counts['modified'] ?? 0)) ?></div>
      <div class="stat__meta"><span>哈希或大小变化</span></div>
    </article>
    <article class="stat">
      <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--warn"><?= icon('trash', 16) ?></span>删除</span></div>
      <div class="stat__value"><?= number_format((int)($counts['removed'] ?? 0)) ?></div>
      <div class="stat__meta"><span>基线里有、现在没有</span></div>
    </article>
  </section>

  <?php if (!empty($result['clean'])): ?>
    <div class="alert alert--success mt-18">
      <?= icon('check', 16) ?>
      <span>与基线完全一致，没有发现文件变动。</span>
    </div>
  <?php else: ?>
    <?php foreach ([
        ['key' => 'added', 'title' => '新增文件', 'badge' => 'badge--warn'],
        ['key' => 'modified', 'title' => '被修改的文件', 'badge' => 'badge--danger'],
        ['key' => 'removed', 'title' => '被删除的文件', 'badge' => 'badge--muted'],
    ] as $block): ?>
      <?php $rows = $result[$block['key']] ?? []; ?>
      <?php if ($rows): ?>
        <section class="card mt-18">
          <div class="card__head">
            <span class="card__title"><?= icon('list', 17) ?><?= e($block['title']) ?></span>
            <span class="badge <?= e($block['badge']) ?>"><?= number_format((int)($counts[$block['key']] ?? 0)) ?></span>
          </div>
          <div class="card__body card__body--flush">
            <div class="table-wrap">
              <table class="table table--compact">
                <thead><tr><th>相对路径</th><th style="width:110px">大小</th><th style="width:170px">修改时间</th><th style="width:70px"></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                  <tr>
                    <td class="mono small" style="word-break:break-all"><?= e($row['path']) ?></td>
                    <td class="small"><?= bytes((int)($row['size'] ?? 0)) ?>
                      <?php if (isset($row['old_size']) && (int)$row['old_size'] !== (int)$row['size']): ?>
                        <span class="muted">（原 <?= bytes((int)$row['old_size']) ?>）</span>
                      <?php endif; ?>
                    </td>
                    <td class="small muted"><?= !empty($row['mtime']) ? e(date('Y-m-d H:i:s', (int)$row['mtime'])) : '—' ?></td>
                    <td class="td-actions">
                      <?php if ($block['key'] !== 'removed'): ?>
                        <a class="icon-btn" href="<?= url('/files?path=' . urlencode($site['name'] . '/' . $row['path'])) ?>" title="打开文件"><?= icon('edit', 13) ?></a>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </section>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endif; ?>
<?php endif; ?>

<?php if ($isScan): ?>
  <section class="stats mt-18" style="grid-template-columns:repeat(4,minmax(0,1fr))">
    <article class="stat">
      <div class="stat__head"><span class="stat__label"><span class="stat__icon"><?= icon('search', 16) ?></span>已扫描</span></div>
      <div class="stat__value"><?= number_format((int)($result['scanned'] ?? 0)) ?></div>
      <div class="stat__meta"><span>跳过（非脚本/超大）</span><span><?= number_format((int)($result['skipped'] ?? 0)) ?></span></div>
    </article>
    <article class="stat">
      <div class="stat__head"><span class="stat__label"><span class="stat__icon <?= $highCount > 0 ? 'stat__icon--warn' : 'stat__icon--ok' ?>"><?= icon('alert', 16) ?></span>高危命中</span></div>
      <div class="stat__value"><?= number_format($highCount) ?></div>
      <div class="stat__meta"><span>需人工确认</span></div>
    </article>
    <article class="stat">
      <div class="stat__head"><span class="stat__label"><span class="stat__icon"><?= icon('eye', 16) ?></span>中危命中</span></div>
      <div class="stat__value"><?= number_format($mediumCount) ?></div>
      <div class="stat__meta"><span>可能误报</span></div>
    </article>
    <article class="stat">
      <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--ok"><?= icon('clock', 16) ?></span>扫描时间</span></div>
      <div class="stat__value" style="font-size:18px"><?= e((string)($result['run_at'] ?? '')) ?></div>
      <div class="stat__meta"><span>规则数</span><span><?= count($rules) ?></span></div>
    </article>
  </section>

  <?php if (!empty($result['unreadable'])): ?>
    <div class="alert alert--warning mt-18">
      <?= icon('alert', 16) ?>
      <span>
        有 <?= count($result['unreadable']) ?> 个文件无法读取（权限异常、被杀软锁定或内容被拦截），无法判断安全性：
        <span class="mono small"><?= e(implode('、', array_slice($result['unreadable'], 0, 8))) ?></span><?= count($result['unreadable']) > 8 ? ' 等' : '' ?>
      </span>
    </div>
  <?php endif; ?>

  <?php if (!$hits): ?>
    <div class="alert alert--success mt-18">
      <?= icon('check', 16) ?>
      <span>未发现可疑代码。注意：特征扫描只能发现常见手法，加密混淆的马需要人工复核或专业工具。</span>
    </div>
  <?php else: ?>
    <section class="card mt-18">
      <div class="card__head">
        <span class="card__title"><?= icon('alert', 17) ?>可疑代码命中</span>
        <span class="badge badge--danger"><?= count($hits) ?> 处</span>
      </div>
      <div class="card__body card__body--flush">
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th style="width:80px">级别</th><th>文件 / 行</th><th>规则</th><th>代码片段</th><th style="width:60px"></th></tr></thead>
            <tbody>
            <?php foreach ($hits as $hit): ?>
              <tr>
                <td><span class="badge <?= $hit['level'] === 'high' ? 'badge--danger' : 'badge--warn' ?>"><?= $hit['level'] === 'high' ? '高危' : '中危' ?></span></td>
                <td>
                  <div class="mono small" style="word-break:break-all"><?= e($hit['path']) ?></div>
                  <div class="cell-sub">第 <?= (int)$hit['line'] ?> 行 · <?= bytes((int)$hit['size']) ?></div>
                </td>
                <td class="small"><?= e($hit['rule']) ?></td>
                <td class="mono small" style="word-break:break-all;max-width:420px"><?= e($hit['snippet']) ?></td>
                <td class="td-actions">
                  <a class="icon-btn" href="<?= url('/files?path=' . urlencode($site['name'] . '/' . $hit['path'])) ?>" title="打开文件"><?= icon('edit', 13) ?></a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="card__foot">
        <span class="small muted">命中不等于一定是木马：主题/插件里也可能出现 <code class="mono">eval</code> 等写法，请结合文件来源与修改时间人工判断。</span>
      </div>
    </section>
  <?php endif; ?>
<?php endif; ?>

<?php if (!$result): ?>
<section class="card mt-18">
  <div class="card__body">
    <div class="empty">
      <?= icon('shield', 30) ?>
      <p>还没有执行过检查</p>
      <small>建议流程：先「建立基线」→ 之后定期「检查篡改」；任何时候都可以「扫描 Webshell」</small>
    </div>
  </div>
</section>
<?php endif; ?>
