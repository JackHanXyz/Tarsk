<?php
declare(strict_types=1);
$siteMatched = [];
foreach ($sites as $site) {
    $siteMatched[(string)$site['name']] = (int)$site['id'];
}
?>
<div class="section-head">
  <div>
    <h2>备份管理</h2>
    <p>站点打包为 zip、数据库导出为 sql，可直接下载或一键恢复。保留策略：每类目标保留最近 <?= (int)$retention ?> 份。</p>
  </div>
  <div class="toolbar">
    <button class="btn btn--ghost btn--sm" type="button" data-open="dbBackup" <?= $databases ? '' : 'disabled' ?>><?= icon('database', 14) ?>备份数据库</button>
    <button class="btn btn--primary btn--sm" type="button" data-open="siteBackup" <?= ($sites && $pack_available) ? '' : 'disabled' ?>><?= icon('save', 14) ?>备份站点</button>
  </div>
</div>

<?php if (!$pack_available): ?>
  <div class="alert alert--warning">
    <?= icon('alert', 16) ?>
    <span>当前 PHP 未启用 <code>zip</code> 扩展且系统未找到 <code>tar</code>，站点备份不可用；数据库备份不受影响。</span>
  </div>
<?php endif; ?>

<div class="grid grid--2">
  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('globe', 17) ?>站点备份<span class="card__desc"><?= count($site_backups) ?> 份</span></span>
    </div>
    <div class="card__body card__body--flush">
      <?php if (!$site_backups): ?>
        <div class="empty">
          <?= icon('globe', 30) ?>
          <p>还没有站点备份</p>
          <small>备份内容为站点目录全部文件（zip）</small>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>站点</th><th>大小</th><th>时间</th><th style="text-align:right">操作</th></tr></thead>
            <tbody>
            <?php foreach ($site_backups as $item): ?>
              <?php $targetId = $siteMatched[$item['target']] ?? 0; ?>
              <tr>
                <td>
                  <div class="cell-main"><?= e($item['target'] ?: $item['name']) ?><?= !empty($item['encrypted']) ? ' <span class="badge badge--warn"><i></i>已加密</span>' : '' ?></div>
                  <div class="cell-sub mono"><?= e($item['name']) ?></div>
                </td>
                <td class="small"><?= bytes($item['size']) ?></td>
                <td class="small muted"><?= e(date('Y-m-d H:i', $item['mtime'])) ?></td>
                <td>
                  <div class="td-actions">
                    <a class="icon-btn" href="<?= url('/backups/download?name=' . urlencode($item['name'])) ?>" title="下载"><?= icon('download', 13) ?></a>
                    <?php if ($targetId > 0): ?>
                      <button class="icon-btn" type="button" title="恢复到该站点"
                              data-restore data-kind="site" data-name="<?= e($item['name']) ?>"
                              data-site-id="<?= $targetId ?>" data-target="<?= e($item['target']) ?>"
                              data-enc="<?= !empty($item['encrypted']) ? '1' : '0' ?>"><?= icon('refresh', 13) ?></button>
                    <?php endif; ?>
                    <form method="post" action="<?= url('/backups/delete') ?>" data-confirm="确定删除备份 <?= e($item['name']) ?>？">
                      <?= csrf_field() ?>
                      <input type="hidden" name="name" value="<?= e($item['name']) ?>">
                      <button class="icon-btn icon-btn--danger" type="submit" title="删除"><?= icon('trash', 13) ?></button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </article>

  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('database', 17) ?>数据库备份<span class="card__desc"><?= count($db_backups) ?> 份</span></span>
    </div>
    <div class="card__body card__body--flush">
      <?php if (!$db_backups): ?>
        <div class="empty">
          <?= icon('database', 30) ?>
          <p>还没有数据库备份</p>
          <small>使用 mysqldump 导出，包含表结构与数据</small>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>数据库</th><th>大小</th><th>时间</th><th style="text-align:right">操作</th></tr></thead>
            <tbody>
            <?php foreach ($db_backups as $item): ?>
              <tr>
                <td>
                  <div class="cell-main mono"><?= e($item['target'] ?: $item['name']) ?><?= !empty($item['encrypted']) ? ' <span class="badge badge--warn"><i></i>已加密</span>' : '' ?></div>
                  <div class="cell-sub mono"><?= e($item['name']) ?></div>
                </td>
                <td class="small"><?= bytes($item['size']) ?></td>
                <td class="small muted"><?= e(date('Y-m-d H:i', $item['mtime'])) ?></td>
                <td>
                  <div class="td-actions">
                    <a class="icon-btn" href="<?= url('/backups/download?name=' . urlencode($item['name'])) ?>" title="下载"><?= icon('download', 13) ?></a>
                    <button class="icon-btn" type="button" title="恢复到该数据库"
                            data-restore data-kind="db" data-name="<?= e($item['name']) ?>"
                            data-db-name="<?= e($item['target']) ?>" data-target="<?= e($item['target']) ?>"
                            data-enc="<?= !empty($item['encrypted']) ? '1' : '0' ?>"><?= icon('refresh', 13) ?></button>
                    <form method="post" action="<?= url('/backups/delete') ?>" data-confirm="确定删除备份 <?= e($item['name']) ?>？">
                      <?= csrf_field() ?>
                      <input type="hidden" name="name" value="<?= e($item['name']) ?>">
                      <button class="icon-btn icon-btn--danger" type="submit" title="删除"><?= icon('trash', 13) ?></button>
                    </form>
                  </div>
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

<div class="card mt-18">
  <div class="card__body">
    <dl class="kv">
      <dt>备份目录</dt><dd class="mono small"><?= e($backup_dir) ?></dd>
      <dt>占用空间</dt><dd><?= bytes($total_size) ?></dd>
      <dt>定时备份</dt><dd>在「计划任务」中新建「站点备份 / 数据库备份」类型任务即可自动执行</dd>
    </dl>
  </div>
</div>

<dialog class="modal" id="siteBackup">
  <form method="post" action="<?= url('/backups/create') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="kind" value="site">
    <div class="modal__head">
      <h3>备份站点</h3>
      <button class="icon-btn" type="button" data-close="siteBackup"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <div class="form-grid">
        <div class="field span-2">
          <label>选择站点</label>
          <select class="input" name="site_id" required>
            <option value="">请选择</option>
            <?php foreach ($sites as $site): ?>
              <option value="<?= (int)$site['id'] ?>"><?= e($site['name']) ?><?= $site['domain'] !== '' ? '（' . e($site['domain']) . '）' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">打包站点目录下全部文件，生成 zip 存入备份目录。</span>
        </div>
        <div class="field span-2">
          <label>加密口令（可选）</label>
          <input class="input" type="password" name="password" minlength="8" autocomplete="new-password" placeholder="留空则不加密；填写则 AES-256-GCM 加密，恢复时需输入">
          <span class="hint">口令不会入库，请自行记录；丢失后无法恢复备份。</span>
        </div>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="siteBackup">取消</button>
      <button class="btn btn--primary" type="submit"><?= icon('save', 14) ?>开始备份</button>
    </div>
  </form>
</dialog>

<dialog class="modal" id="dbBackup">
  <form method="post" action="<?= url('/backups/create') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="kind" value="db">
    <div class="modal__head">
      <h3>备份数据库</h3>
      <button class="icon-btn" type="button" data-close="dbBackup"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <div class="form-grid">
        <div class="field span-2">
          <label>选择数据库</label>
          <select class="input" name="db_name" required>
            <option value="">请选择</option>
            <?php foreach ($databases as $db): ?>
              <option value="<?= e($db['name']) ?>"><?= e($db['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">使用面板配置的 MySQL 账号导出，包含表结构与数据。</span>
        </div>
        <div class="field span-2">
          <label>加密口令（可选）</label>
          <input class="input" type="password" name="password" minlength="8" autocomplete="new-password" placeholder="留空则不加密；填写则 AES-256-GCM 加密，恢复时需输入">
        </div>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="dbBackup">取消</button>
      <button class="btn btn--primary" type="submit"><?= icon('save', 14) ?>开始备份</button>
    </div>
  </form>
</dialog>

<dialog class="modal" id="restoreBackup">
  <form method="post" action="<?= url('/backups/restore') ?>" data-confirm="恢复会覆盖现有数据，确定继续？">
    <?= csrf_field() ?>
    <input type="hidden" name="kind" id="rstKind" value="">
    <input type="hidden" name="name" id="rstName" value="">
    <input type="hidden" name="site_id" id="rstSiteId" value="">
    <input type="hidden" name="db_name" id="rstDbName" value="">
    <div class="modal__head">
      <h3>恢复备份</h3>
      <button class="icon-btn" type="button" data-close="restoreBackup"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <p class="small muted" id="rstHint">将把备份内容覆盖到目标。</p>
      <p class="small">目标：<strong class="mono" id="rstTarget"></strong></p>
      <div class="field mt-12">
        <label>解密口令</label>
        <input class="input" type="password" name="password" id="rstPwd" autocomplete="off" placeholder="未加密的备份可留空">
        <span class="hint" id="rstEncHint">该备份未加密，口令可留空</span>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="restoreBackup">取消</button>
      <button class="btn btn--danger" type="submit"><?= icon('refresh', 14) ?>确认恢复</button>
    </div>
  </form>
</dialog>
