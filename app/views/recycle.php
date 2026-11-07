<?php
declare(strict_types=1);
?>
<div class="section-head">
  <div>
    <h2>回收站</h2>
    <p>文件管理器删除的内容会先移入这里，可还原或彻底删除。</p>
  </div>
  <div class="toolbar">
    <?php if ($items): ?>
      <form method="post" action="<?= url('/recycle/clear') ?>" data-confirm="确定清空回收站？所有内容将被彻底删除且无法恢复！">
        <?= csrf_field() ?>
        <button class="btn btn--danger btn--sm" type="submit"><?= icon('trash', 14) ?>清空回收站</button>
      </form>
    <?php endif; ?>
    <a class="btn btn--ghost btn--sm" href="<?= url('/files') ?>"><?= icon('folder', 14) ?>返回文件</a>
  </div>
</div>

<?php if (!$enabled): ?>
  <div class="alert alert--warning">
    <?= icon('alert', 16) ?>
    <span>回收站功能当前已关闭，删除文件将直接彻底删除。可在「面板设置 → 面板安全」中开启。</span>
  </div>
<?php endif; ?>

<div class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('trash', 17) ?>已删除内容（<?= count($items) ?>）</span>
    <span class="small muted">共占用 <?= bytes($totalSize) ?></span>
  </div>
  <div class="card__body card__body--flush">
    <?php if (!$items): ?>
      <div class="empty">
        <?= icon('trash', 30) ?>
        <p>回收站是空的</p>
        <small>在文件管理器中删除文件后会出现在这里</small>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr><th>名称</th><th>原始位置</th><th>大小</th><th>删除时间</th><th style="text-align:right">操作</th></tr>
          </thead>
          <tbody>
          <?php foreach ($items as $item): ?>
            <tr>
              <td class="cell-main">
                <?= icon((int)$item['is_dir'] === 1 ? 'folder' : 'save', 14) ?>
                <?= e($item['name']) ?>
              </td>
              <td class="small muted mono"><?= e($item['orig_path']) ?></td>
              <td><?= (int)$item['size'] > 0 ? bytes((int)$item['size']) : '—' ?></td>
              <td class="small muted"><?= e($item['deleted_at']) ?></td>
              <td>
                <div class="td-actions">
                  <form method="post" action="<?= url('/recycle/restore') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                    <button class="icon-btn" type="submit" title="还原"><?= icon('refresh', 13) ?></button>
                  </form>
                  <form method="post" action="<?= url('/recycle/delete') ?>"
                    data-confirm="确定彻底删除 <?= e($item['name']) ?>？此操作无法恢复！">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                    <button class="icon-btn icon-btn--danger" type="submit" title="彻底删除"><?= icon('trash', 13) ?></button>
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
  <div class="card__foot"><span class="small muted">回收站内容存储在面板 storage/trash 目录，彻底删除后无法恢复。</span></div>
</div>
