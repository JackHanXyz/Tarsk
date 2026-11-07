<?php
declare(strict_types=1);

$extColors = ['php' => 'code', 'html' => 'code', 'js' => 'code', 'css' => 'code', 'json' => 'code', 'sql' => 'code', 'md' => 'code'];
?>

<div class="section-head">
  <div>
    <h2>文件管理</h2>
    <p>直接浏览与编辑站点目录中的真实文件。</p>
  </div>
  <div class="toolbar">
    <button class="btn btn--ghost btn--sm" data-open="mkdirDialog"><?= icon('folder', 14) ?>新建目录</button>
    <button class="btn btn--ghost btn--sm" data-open="fileCreateDialog"><?= icon('plus', 14) ?>新建文件</button>
    <button class="btn btn--ghost btn--sm" data-trigger="fileUploadInput"><?= icon('upload', 14) ?>上传文件</button>
    <a class="btn btn--ghost btn--sm" href="<?= url('/files?path=' . urlencode($rel)) ?>"><?= icon('refresh', 14) ?>刷新</a>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <div class="toolbar" style="gap:6px">
      <a class="icon-btn" href="<?= url('/files' . ($rel && dirname($rel) !== '.' ? '?path=' . urlencode(dirname($rel)) : '')) ?>" title="返回上级"><?= icon('arrow-left', 14) ?></a>
      <a class="badge badge--info" href="<?= url('/files') ?>"><?= icon('folder', 12) ?>根目录</a>
      <?php foreach ($crumbs as $crumb): ?>
        <span class="muted small">/</span>
        <a class="badge badge--muted" href="<?= url('/files?path=' . urlencode($crumb['path'])) ?>"><?= e($crumb['name']) ?></a>
      <?php endforeach; ?>
    </div>
    <span class="small muted"><?= count($entries) ?> 项 · <?= bytes($total) ?></span>
  </div>

  <div class="card__body card__body--flush">
    <?php if (!$entries): ?>
      <div class="empty">
        <?= icon('folder', 30) ?>
        <p>目录为空</p>
        <small>上传文件或新建目录开始建站</small>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>名称</th>
              <th style="width:120px">大小</th>
              <th style="width:150px">修改时间</th>
              <th style="width:90px">权限</th>
              <th style="width:150px;text-align:right">操作</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($entries as $entry): ?>
            <?php
            $childRel = $rel === '' ? $entry['name'] : $rel . '/' . $entry['name'];
            ?>
            <tr class="file-row" data-search="<?= e(strtolower($entry['name'])) ?>">
              <td>
                <div class="file-name">
                  <span class="file-ico <?= $entry['is_dir'] ? 'file-ico--dir' : (isset($extColors[$entry['ext']]) ? 'file-ico--code' : '') ?>">
                    <?= $entry['is_dir'] ? icon('folder', 15) : e(mb_substr($entry['ext'] ?: 'file', 0, 4)) ?>
                  </span>
                  <?php if ($entry['is_dir']): ?>
                    <a class="cell-main" href="<?= url('/files?path=' . urlencode($childRel)) ?>"><?= e($entry['name']) ?></a>
                  <?php else: ?>
                    <a class="cell-main" href="javascript:void(0)" data-file-open="<?= e($childRel) ?>"><?= e($entry['name']) ?></a>
                  <?php endif; ?>
                </div>
              </td>
              <td class="small"><?= $entry['is_dir'] ? '—' : bytes($entry['size']) ?></td>
              <td class="small muted"><?= date('Y-m-d H:i', $entry['mtime']) ?></td>
              <td class="small mono muted"><?= e($entry['perm']) ?></td>
              <td>
                <div class="td-actions">
                  <?php if (!$entry['is_dir']): ?>
                    <a class="icon-btn" href="<?= url('/files/download?path=' . urlencode($childRel)) ?>" title="下载"><?= icon('download', 14) ?></a>
                    <button class="icon-btn" type="button" title="重命名"
                      data-rename-path="<?= e($childRel) ?>" data-rename-name="<?= e($entry['name']) ?>"><?= icon('edit', 14) ?></button>
                  <?php else: ?>
                    <button class="icon-btn" type="button" title="重命名"
                      data-rename-path="<?= e($childRel) ?>" data-rename-name="<?= e($entry['name']) ?>"><?= icon('edit', 14) ?></button>
                  <?php endif; ?>
                  <button class="icon-btn icon-btn--danger" type="button" title="删除"
                    data-delete-path="<?= e($childRel) ?>" data-delete-name="<?= e($entry['name']) ?>"><?= icon('trash', 14) ?></button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  <div class="card__foot">
    <span class="small muted mono"><?= e($root . ($rel ? '/' . $rel : '')) ?></span>
  </div>
</div>

<form id="fileUploadForm" method="post" action="<?= url('/files/upload') ?>" enctype="multipart/form-data" style="display:none">
  <?= csrf_field() ?>
  <input type="hidden" name="path" value="<?= e($rel) ?>">
  <input type="file" id="fileUploadInput" name="file[]" multiple>
</form>

<dialog class="modal modal--wide" id="editorDialog">
  <form method="post" action="<?= url('/files/save') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="path" id="editorPath">
    <div class="modal__head">
      <h3 id="editorTitle">编辑文件</h3>
      <div class="toolbar">
        <span class="badge badge--muted" id="editorMeta">UTF-8</span>
        <button class="icon-btn" type="button" data-close="editorDialog"><?= icon('x', 14) ?></button>
      </div>
    </div>
    <div class="modal__body" style="padding:0">
      <textarea id="editorContent" name="content" spellcheck="false"
        style="width:100%;min-height:52vh;border:0;outline:none;padding:16px 20px;font-family:var(--mono);font-size:12.5px;line-height:1.75;resize:vertical;background:#fcfdff"></textarea>
    </div>
    <div class="modal__foot">
      <span class="small muted" id="editorHint">支持 UTF-8 文本文件，最大 4 MB</span>
      <div class="toolbar">
        <button class="btn btn--ghost" type="button" data-close="editorDialog">取消</button>
        <button class="btn btn--primary" type="submit"><?= icon('save', 14) ?>保存文件</button>
      </div>
    </div>
  </form>
</dialog>

<dialog class="modal" id="mkdirDialog">
  <form method="post" action="<?= url('/files/mkdir') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="path" value="<?= e($rel) ?>">
    <div class="modal__head">
      <h3>新建目录</h3>
      <button class="icon-btn" type="button" data-close="mkdirDialog"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <div class="field">
        <label>目录名称</label>
        <input class="input" type="text" name="name" placeholder="assets" required autofocus>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="mkdirDialog">取消</button>
      <button class="btn btn--primary" type="submit">创建</button>
    </div>
  </form>
</dialog>

<dialog class="modal" id="fileCreateDialog">
  <form method="post" action="<?= url('/files/create') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="path" value="<?= e($rel) ?>">
    <div class="modal__head">
      <h3>新建文件</h3>
      <button class="icon-btn" type="button" data-close="fileCreateDialog"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <div class="field">
        <label>文件名称</label>
        <input class="input" type="text" name="name" placeholder="index.php" required autofocus>
        <span class="hint">创建后可在文件列表中点击文件名编辑内容。</span>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="fileCreateDialog">取消</button>
      <button class="btn btn--primary" type="submit">创建</button>
    </div>
  </form>
</dialog>

<dialog class="modal" id="renameDialog">
  <form method="post" action="<?= url('/files/rename') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="path" id="renamePath">
    <div class="modal__head">
      <h3>重命名</h3>
      <button class="icon-btn" type="button" data-close="renameDialog"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <div class="field">
        <label>新名称</label>
        <input class="input" type="text" name="name" id="renameName" required>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="renameDialog">取消</button>
      <button class="btn btn--primary" type="submit">保存</button>
    </div>
  </form>
</dialog>

<dialog class="modal" id="deleteDialog">
  <form method="post" action="<?= url('/files/delete') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="path" id="deletePath">
    <div class="modal__head">
      <h3>删除确认</h3>
      <button class="icon-btn" type="button" data-close="deleteDialog"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <p>确定删除 <strong id="deleteName">—</strong> 吗？目录会连同内部文件一并删除，且不可恢复。</p>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="deleteDialog">取消</button>
      <button class="btn btn--danger" type="submit">确认删除</button>
    </div>
  </form>
</dialog>
