<?php
declare(strict_types=1);

$selectedData = null;
foreach ($databases as $db) {
    if ($db['name'] === $selected) {
        $selectedData = $db;
        break;
    }
}
?>
<div class="section-head">
  <div>
    <h2>数据库管理</h2>
    <p>通过面板连接真实 MySQL 实例，创建、浏览与备份数据库。</p>
  </div>
  <div class="toolbar">
    <button class="btn btn--primary btn--sm" data-open="dbCreate"><?= icon('plus', 14) ?>新建数据库</button>
  </div>
</div>

<?php if (!$info['connected']): ?>
  <div class="alert alert--error">
    <?= icon('alert', 16) ?>
    <span>
      无法连接 MySQL：<?= e($info['error']) ?>。当前使用账号 <strong><?= e($info['user']) ?></strong>，
      可在 <a href="<?= url('/settings') ?>">面板设置</a> 中填写具备管理权限的账号后重试。
    </span>
  </div>
<?php elseif ($info['version']): ?>
  <div class="stats" style="grid-template-columns:repeat(4,minmax(0,1fr))">
    <article class="stat">
      <div class="stat__head"><span class="stat__label"><span class="stat__icon"><?= icon('database', 16) ?></span>MySQL 版本</span></div>
      <div class="stat__value" style="font-size:20px"><?= e($info['version']) ?></div>
      <div class="stat__meta"><span>连接账号</span><span class="mono"><?= e($info['user']) ?></span></div>
    </article>
    <article class="stat">
      <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--ok"><?= icon('globe', 16) ?></span>数据库数量</span></div>
      <div class="stat__value"><?= count($databases) ?><small>个</small></div>
      <div class="stat__meta"><span>已排除系统库</span></div>
    </article>
    <article class="stat">
      <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--violet"><?= icon('activity', 16) ?></span>运行时长</span></div>
      <div class="stat__value" style="font-size:20px"><?= e(uptime_text((int)$info['uptime'])) ?></div>
      <div class="stat__meta"><span>当前连接 <?= (int)$info['threads'] ?></span></div>
    </article>
    <article class="stat">
      <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--warn"><?= icon('zap', 16) ?></span>累计查询</span></div>
      <div class="stat__value" style="font-size:20px"><?= number_format((int)$info['queries']) ?></div>
      <div class="stat__meta"><span>自服务启动以来</span></div>
    </article>
  </div>
<?php endif; ?>

<section class="grid grid--split mt-18">
  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('database', 17) ?>数据库列表</span>
      <span class="badge badge--muted"><?= count($databases) ?></span>
    </div>
    <div class="card__body">
      <?php if (!$databases): ?>
        <div class="empty" style="padding:26px 0">
          <?= icon('database', 28) ?>
          <p>没有可见的数据库</p>
          <small>账号权限不足或实例暂无数据</small>
        </div>
      <?php else: ?>
        <div class="db-list">
          <?php foreach ($databases as $db): ?>
            <div class="db-item<?= $selected === $db['name'] ? ' is-active' : '' ?>">
              <a href="<?= url('/databases?db=' . urlencode($db['name'])) ?>" style="flex:1;min-width:0">
                <div class="db-name"><?= e($db['name']) ?></div>
                <div class="small muted"><?= (int)$db['tables'] ?> 张表 · <?= bytes($db['bytes']) ?></div>
              </a>
              <div class="td-actions">
                <form method="post" action="<?= url('/databases/backup') ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="name" value="<?= e($db['name']) ?>">
                  <button class="icon-btn" type="submit" title="备份"><?= icon('download', 13) ?></button>
                </form>
                <form method="post" action="<?= url('/databases/drop') ?>" data-confirm="确定删除数据库 <?= e($db['name']) ?>？此操作不可恢复。">
                  <?= csrf_field() ?>
                  <input type="hidden" name="name" value="<?= e($db['name']) ?>">
                  <button class="icon-btn icon-btn--danger" type="submit" title="删除"><?= icon('trash', 13) ?></button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="card__foot">
      <span class="small muted">备份文件输出到 <?= e(backup_path()) ?></span>
    </div>
  </article>

  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('list', 17) ?><?= $selected !== '' ? e($selected) . ' · 数据表' : '数据表' ?></span>
      <?php if ($selected !== ''): ?>
        <span class="badge badge--info"><?= count($tables) ?> 张表</span>
      <?php endif; ?>
    </div>
    <div class="card__body card__body--flush">
      <?php if ($selected === ''): ?>
        <div class="empty">
          <?= icon('database', 28) ?>
          <p>选择左侧数据库</p>
          <small>查看数据表结构、行数与占用空间</small>
        </div>
      <?php elseif (!$tables): ?>
        <div class="empty"><?= icon('list', 26) ?><p>该数据库暂无数据表</p></div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>数据表</th><th>引擎</th><th>行数</th><th>大小</th><th>字符集</th></tr></thead>
            <tbody>
            <?php foreach ($tables as $table): ?>
              <tr>
                <td class="cell-main mono"><?= e($table['table_name'] ?? $table['TABLE_NAME'] ?? '') ?></td>
                <td class="small"><?= e($table['engine'] ?? $table['ENGINE'] ?? '') ?></td>
                <td class="small"><?= number_format((int)($table['table_rows'] ?? $table['TABLE_ROWS'] ?? 0)) ?></td>
                <td class="small"><?= bytes((int)($table['data_length'] ?? 0) + (int)($table['index_length'] ?? 0)) ?></td>
                <td class="small muted"><?= e($table['table_collation'] ?? $table['TABLE_COLLATION'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </article>
</section>

<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('save', 17) ?>备份文件</span>
    <span class="badge badge--muted"><?= count($backups) ?></span>
  </div>
  <div class="card__body card__body--flush">
    <?php if (!$backups): ?>
      <div class="empty" style="padding:26px">
        <?= icon('save', 26) ?>
        <p>还没有备份文件</p>
        <small>在数据库列表点击下载图标即可生成 .sql 备份</small>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>文件</th><th>大小</th><th>生成时间</th></tr></thead>
          <tbody>
          <?php foreach ($backups as $backup): ?>
            <tr>
              <td class="mono cell-main"><?= e($backup['name']) ?></td>
              <td><?= bytes($backup['size']) ?></td>
              <td class="muted small"><?= date('Y-m-d H:i', $backup['mtime']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<dialog class="modal" id="dbCreate">
  <form method="post" action="<?= url('/databases/create') ?>">
    <?= csrf_field() ?>
    <div class="modal__head">
      <h3>新建数据库</h3>
      <button class="icon-btn" type="button" data-close="dbCreate"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <div class="form-grid">
        <div class="field">
          <label>数据库名称</label>
          <input class="input" type="text" name="name" placeholder="site_db" required pattern="[A-Za-z0-9_]+">
        </div>
        <div class="field">
          <label>字符集</label>
          <select class="input" name="charset">
            <option value="utf8mb4">utf8mb4（推荐）</option>
            <option value="utf8">utf8</option>
            <option value="gbk">gbk</option>
          </select>
        </div>
        <div class="field span-2">
          <label>备注</label>
          <input class="input" type="text" name="remark" placeholder="可选">
        </div>
      </div>
      <p class="small muted mt-12">创建数据库需要账号具备 CREATE 权限；如失败，可在面板设置中配置 root 账号。</p>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="dbCreate">取消</button>
      <button class="btn btn--primary" type="submit">创建</button>
    </div>
  </form>
</dialog>
