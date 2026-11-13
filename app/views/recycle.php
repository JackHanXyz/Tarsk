<?php
declare(strict_types=1);
?>
<div class="section-head">
  <div>
    <h2>回收站</h2>
    <p>文件管理器删除的内容会先移入这里，可还原、按时间批量清理或彻底删除。</p>
  </div>
  <div class="toolbar">
    <?php if ($items): ?>
      <button class="btn btn--ghost btn--sm" type="button" data-open="recycleCleanup"><?= icon('clock', 14) ?>按时间清理</button>
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

<?php if ($items): ?>
<section class="stats" style="grid-template-columns:repeat(5,minmax(0,1fr))">
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon"><?= icon('trash', 16) ?></span>总项目</span></div>
    <div class="stat__value"><?= count($items) ?></div>
    <div class="stat__meta"><span>占用</span><span><?= bytes($totalSize) ?></span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--ok"><?= icon('clock', 16) ?></span>24 小时内</span></div>
    <div class="stat__value"><?= (int)$buckets['today'] ?></div>
    <div class="stat__meta"><span>可直接还原</span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon"><?= icon('clock', 16) ?></span>1~7 天</span></div>
    <div class="stat__value"><?= (int)$buckets['week'] ?></div>
    <div class="stat__meta"><span>建议保留</span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--warn"><?= icon('clock', 16) ?></span>7~30 天</span></div>
    <div class="stat__value"><?= (int)$buckets['month'] ?></div>
    <div class="stat__meta"><span>可考虑清理</span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--warn"><?= icon('alert', 16) ?></span>30 天以上</span></div>
    <div class="stat__value"><?= (int)$buckets['older'] ?></div>
    <div class="stat__meta"><span>建议清理</span></div>
  </article>
</section>
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
      <form id="recycleBatch" method="post" action="<?= url('/recycle/batch') ?>" data-confirm="彻底删除选中的项目？此操作无法恢复！" hidden>
        <?= csrf_field() ?>
      </form>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th style="width:36px"><input type="checkbox" id="recycleAll" title="全选"></th>
              <th>名称</th><th>原始位置</th><th>大小</th><th>删除时间</th><th style="text-align:right">操作</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($items as $item): ?>
            <tr>
              <td><input type="checkbox" name="ids[]" form="recycleBatch" value="<?= (int)$item['id'] ?>"></td>
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
  <div class="card__foot" style="display:flex;justify-content:space-between;align-items:center">
    <span class="small muted">回收站内容存储在面板 storage/trash 目录，彻底删除后无法恢复。</span>
    <?php if ($items): ?>
      <button class="btn btn--danger-ghost btn--sm" type="submit" form="recycleBatch"><?= icon('trash', 14) ?>删除选中项</button>
    <?php endif; ?>
  </div>
</div>

<dialog class="modal" id="recycleCleanup">
  <form method="post" action="<?= url('/recycle/cleanup') ?>" data-confirm="将彻底删除符合条件的回收站项目，确认继续？">
    <?= csrf_field() ?>
    <div class="modal__head">
      <h3>按时间批量清理</h3>
      <button class="icon-btn" type="button" data-close="recycleCleanup"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <div class="form-grid">
        <div class="field">
          <label>清理范围</label>
          <select class="input" name="scope">
            <option value="older">删除指定天数之前的项目</option>
            <option value="all">删除全部（等同清空）</option>
          </select>
        </div>
        <div class="field">
          <label>天数</label>
          <input class="input mono" type="number" name="days" min="1" max="3650" value="30">
          <span class="hint">例如填 7 表示删除 7 天前删除的文件</span>
        </div>
      </div>
      <div class="alert alert--warning mt-12">
        <?= icon('alert', 16) ?>
        <span>操作会同时删除 storage/trash 下的真实文件，不可恢复。</span>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="recycleCleanup">取消</button>
      <button class="btn btn--danger" type="submit">开始清理</button>
    </div>
  </form>
</dialog>

<script>
(function () {
  var master = document.getElementById('recycleAll');
  var boxes = Array.prototype.slice.call(document.querySelectorAll('input[name="ids[]"]'));
  if (!master || !boxes.length) { return; }
  master.addEventListener('change', function () {
    boxes.forEach(function (box) { box.checked = master.checked; });
  });
  boxes.forEach(function (box) {
    box.addEventListener('change', function () {
      master.checked = boxes.every(function (b) { return b.checked; });
    });
  });
})();
</script>
