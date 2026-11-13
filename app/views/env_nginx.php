<?php
declare(strict_types=1);
?>
<div class="section-head">
  <div>
    <h2>Nginx 配置</h2>
    <p>调整托管 Nginx 的全局参数（worker、gzip、上传上限等），保存前自动 <code class="mono">nginx -t</code> 校验，未通过直接回滚。</p>
  </div>
  <div class="toolbar">
    <span class="badge <?= $running ? 'badge--ok' : 'badge--muted' ?>"><i></i><?= $running ? '运行中' : '未运行' ?></span>
    <?php if ($version !== ''): ?><span class="badge badge--info">nginx <?= e($version) ?></span><?php endif; ?>
    <?php if ($installed): ?>
      <form method="post" action="<?= url('/env/nginx/test') ?>">
        <?= csrf_field() ?>
        <button class="btn btn--ghost btn--sm" type="submit"><?= icon('check', 14) ?>语法检测</button>
      </form>
      <form method="post" action="<?= url('/env/nginx/restart') ?>">
        <?= csrf_field() ?>
        <button class="btn btn--ghost btn--sm" type="submit"><?= icon('refresh', 14) ?>重启 Nginx</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!$installed): ?>
  <div class="alert alert--warning">
    <?= icon('alert', 16) ?>
    <span>未检测到托管 Nginx。请先在<a href="<?= url('/store') ?>">软件商店</a>安装，系统自带的 Nginx 配置路径不在面板管理范围内。</span>
  </div>
<?php else: ?>

<div class="alert alert--<?= $test['ok'] ? 'success' : 'error' ?>">
  <?= icon($test['ok'] ? 'check' : 'alert', 16) ?>
  <span><?= $test['ok'] ? '当前配置 nginx -t 校验通过' : '当前配置校验失败：' ?><?= e($test['ok'] ? '' : $test['message']) ?></span>
</div>

<section class="grid grid--sidebar">
  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('settings', 17) ?>全局参数</span>
      <span class="badge badge--muted mono">conf/nginx.conf</span>
    </div>
    <form method="post" action="<?= url('/env/nginx/save') ?>">
      <?= csrf_field() ?>
      <div class="card__body">
        <div class="form-grid">
          <?php foreach ($schema as $name => $meta): ?>
            <div class="field">
              <label class="mono small"><?= e($name) ?></label>
              <?php if ($meta['type'] === 'bool'): ?>
                <select class="input" name="<?= e($name) ?>">
                  <option value="On" <?= strcasecmp((string)$knobs[$name], 'On') === 0 ? 'selected' : '' ?>>On</option>
                  <option value="Off" <?= strcasecmp((string)$knobs[$name], 'Off') === 0 ? 'selected' : '' ?>>Off</option>
                </select>
              <?php else: ?>
                <input class="input mono" type="text" name="<?= e($name) ?>" value="<?= e((string)$knobs[$name]) ?>">
              <?php endif; ?>
              <span class="hint"><?= e($meta['label']) ?><?= $meta['hint'] !== '' ? ' · ' . e($meta['hint']) : '' ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="hint mt-12">保存会重建 <code class="mono">conf/nginx.conf</code>（保留 vhosts 与 zones 的 include），配置校验通过后自动 reload。</p>
      </div>
      <div class="card__foot" style="display:flex;justify-content:flex-end">
        <button class="btn btn--primary" type="submit"><?= icon('save', 14) ?>保存全局参数</button>
      </div>
    </form>
  </article>

  <div class="stack">
    <article class="card">
      <div class="card__head"><span class="card__title"><?= icon('server', 17) ?>运行信息</span></div>
      <div class="card__body">
        <dl class="kv">
          <dt>配置文件</dt><dd class="mono small"><?= e((string)$conf_path) ?></dd>
          <dt>站点入口端口</dt><dd class="mono"><?= (int)$web_port ?></dd>
          <dt>版本</dt><dd class="mono"><?= e($version ?: '未知') ?></dd>
          <dt>限流区文件</dt><dd class="mono small"><?= e($zones_path) ?></dd>
          <dt>配置文件大小</dt><dd><?= bytes(strlen($content)) ?></dd>
        </dl>
      </div>
    </article>

    <article class="card mt-18">
      <div class="card__head">
        <span class="card__title"><?= icon('save', 17) ?>回滚点</span>
        <span class="badge badge--muted"><?= count($backups) ?></span>
      </div>
      <div class="card__body card__body--flush">
        <?php if (!$backups): ?>
          <div class="empty">暂无备份</div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table table--compact">
              <thead><tr><th>时间</th><th>大小</th><th></th></tr></thead>
              <tbody>
              <?php foreach (array_slice($backups, 0, 8) as $b): ?>
                <tr>
                  <td class="small mono"><?= e($b['time']) ?></td>
                  <td class="small"><?= bytes($b['size']) ?></td>
                  <td class="td-actions">
                    <form method="post" action="<?= url('/env/nginx/rollback') ?>" data-confirm="将用该备份覆盖当前 nginx.conf（校验失败会自动还原），确认继续？">
                      <?= csrf_field() ?>
                      <input type="hidden" name="backup" value="<?= e($b['name']) ?>">
                      <button class="icon-btn" type="submit" title="回滚到该版本"><?= icon('refresh', 14) ?></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </article>
  </div>
</section>

<?php if ($limit_sites): ?>
<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('shield', 17) ?>生效中的站点限流</span>
    <a class="badge badge--muted" href="<?= url('/sites') ?>">去站点设置</a>
  </div>
  <div class="card__body card__body--flush">
    <div class="table-wrap">
      <table class="table table--compact">
        <thead><tr><th>站点</th><th>域名</th><th>速率</th><th>burst</th><th>单 IP 并发</th></tr></thead>
        <tbody>
        <?php foreach ($limit_sites as $row): ?>
          <tr>
            <td class="cell-main"><?= e($row['name']) ?></td>
            <td class="small mono"><?= e($row['domain'] ?: '—') ?></td>
            <td class="small"><?= (int)$row['rate_limit'] > 0 ? (int)$row['rate_limit'] . ' r/s' : '不限' ?></td>
            <td class="small"><?= (int)$row['rate_burst'] > 0 ? (int)$row['rate_burst'] : '自动' ?></td>
            <td class="small"><?= (int)$row['conn_limit'] > 0 ? (int)$row['conn_limit'] : '不限' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('list', 17) ?>限流共享内存区</span>
    <span class="badge badge--muted mono">tarsk-zones.conf</span>
  </div>
  <div class="card__body">
    <pre class="pre pre--light"><?= e($zones ?: '# 尚无站点启用限流') ?></pre>
    <p class="hint mt-12">该文件由面板按站点限流配置自动生成，nginx.conf 始终 include 它。手工删除会导致启用限流的站点无法启动。</p>
  </div>
</section>

<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('edit', 17) ?>原始 nginx.conf</span>
    <span class="badge badge--warn">谨慎操作</span>
  </div>
  <form method="post" action="<?= url('/env/nginx/raw') ?>">
    <?= csrf_field() ?>
    <div class="card__body">
      <div class="field">
        <textarea class="input mono" name="content" rows="16" spellcheck="false"><?= e($content) ?></textarea>
        <span class="hint">需自行保留 <code class="mono">include vhosts/*.conf;</code> 与 <code class="mono">include tarsk-zones.conf;</code>，否则站点会全部失效。</span>
      </div>
    </div>
    <div class="card__foot" style="display:flex;justify-content:flex-end">
      <button class="btn btn--primary" type="submit"><?= icon('save', 14) ?>保存并校验</button>
    </div>
  </form>
</section>

<?php endif; ?>
