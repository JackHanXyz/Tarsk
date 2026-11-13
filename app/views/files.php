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
    <button class="btn btn--ghost btn--sm" data-open="chunkUploadDialog"><?= icon('upload', 14) ?>大文件上传</button>
    <button class="btn btn--ghost btn--sm" type="button" data-batch-action="<?= url('/files/compress') ?>"><?= icon('box', 14) ?>压缩</button>
    <button class="btn btn--ghost btn--sm" type="button" data-open="extractDialog"><?= icon('download', 14) ?>解压</button>
    <button class="btn btn--ghost btn--sm" type="button" data-open="fetchDialog"><?= icon('external', 14) ?>远程下载</button>
    <button class="btn btn--danger-ghost btn--sm" type="button" data-batch-action="<?= url('/files/batchDelete') ?>"><?= icon('trash', 14) ?>批量删除</button>
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
      <form id="fileBatchForm" method="post" action="<?= url('/files/batchDelete') ?>">
        <?= csrf_field() ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th style="width:34px"><input type="checkbox" id="fileSelectAll" title="全选"></th>
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
              <td><input type="checkbox" name="paths[]" value="<?= e($childRel) ?>" title="<?= e($entry['name']) ?>"></td>
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
                  <button class="icon-btn" type="button" title="权限" data-open="chmodDialog"
                    data-chmod-path="<?= e($childRel) ?>" data-chmod-mode="<?= e($entry['perm']) ?>"><?= icon('lock', 14) ?></button>
                  <button class="icon-btn icon-btn--danger" type="button" title="删除"
                    data-delete-path="<?= e($childRel) ?>" data-delete-name="<?= e($entry['name']) ?>"><?= icon('trash', 14) ?></button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      </form>
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
        style="width:100%;min-height:52vh;border:0;outline:none;padding:16px 20px;font-family:var(--mono);font-size:12.5px;line-height:1.75;resize:vertical;background:var(--surface-2);color:var(--text)"></textarea>
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

<dialog class="modal" id="fetchDialog">
  <form method="post" action="<?= url('/files/fetch') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="dir" value="<?= e($rel) ?>">
    <div class="modal__head">
      <h3>远程下载</h3>
      <button class="icon-btn" type="button" data-close="fetchDialog"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <div class="field">
        <label>下载链接</label>
        <input class="input" type="url" name="url" placeholder="https://example.com/file.zip" required autofocus>
        <span class="hint">仅支持 http / https，最大 500 MB，保存到当前目录（<?= e($rel === '' ? '站点根目录' : $rel) ?>）。</span>
      </div>
      <div class="field">
        <label>保存名称（可选）</label>
        <input class="input" type="text" name="filename" placeholder="默认取链接中的文件名">
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="fetchDialog">取消</button>
      <button class="btn btn--primary" type="submit"><?= icon('download', 14) ?>开始下载</button>
    </div>
  </form>
</dialog>

<dialog class="modal" id="extractDialog">
  <form method="post" action="<?= url('/files/extract') ?>">
    <?= csrf_field() ?>
    <div class="modal__head">
      <h3>解压归档</h3>
      <button class="icon-btn" type="button" data-close="extractDialog"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <div class="field">
        <label>归档文件路径</label>
        <input class="input" type="text" name="path" placeholder="backup/20260101.zip" required autofocus>
        <span class="hint">相对站点目录，支持 .zip / .tar / .tar.gz / .tgz / .gz，解压到归档文件所在目录。</span>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="extractDialog">取消</button>
      <button class="btn btn--primary" type="submit">开始解压</button>
    </div>
  </form>
</dialog>

<dialog class="modal" id="chmodDialog">
  <form method="post" action="<?= url('/files/chmod') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="path" id="chmodPath">
    <div class="modal__head">
      <h3>修改权限</h3>
      <button class="icon-btn" type="button" data-close="chmodDialog"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <div class="field">
        <label>目标</label>
        <span class="small mono muted" id="chmodTarget">—</span>
      </div>
      <div class="field">
        <label>权限值</label>
        <input class="input" type="text" name="mode" id="chmodMode" placeholder="755" required autofocus>
        <span class="hint">3-4 位八进制，如 644 / 755 / 0755。Windows 下权限位可能不生效，仅 POSIX 有效。</span>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="chmodDialog">取消</button>
      <button class="btn btn--primary" type="submit">保存权限</button>
    </div>
  </form>
</dialog>

<script>
(function () {
  var form = document.getElementById('fileBatchForm');
  if (!form) { return; }

  var boxes = function () {
    return Array.prototype.slice.call(form.querySelectorAll('input[name="paths[]"]'));
  };
  var checked = function () {
    return boxes().filter(function (box) { return box.checked; });
  };

  var selectAll = document.getElementById('fileSelectAll');
  if (selectAll) {
    selectAll.addEventListener('change', function () {
      boxes().forEach(function (box) { box.checked = selectAll.checked; });
    });
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-batch-action]'), function (button) {
    button.addEventListener('click', function () {
      var items = checked();
      if (items.length === 0) {
        window.alert('请先勾选文件或目录');
        return;
      }
      var action = button.getAttribute('data-batch-action') || '';
      if (action.indexOf('batchDelete') !== -1) {
        if (!window.confirm('确定删除选中的 ' + items.length + ' 项吗？可通过回收站还原。')) { return; }
      }
      form.action = action;
      form.submit();
    });
  });

  Array.prototype.forEach.call(document.querySelectorAll('[data-chmod-path]'), function (button) {
    button.addEventListener('click', function () {
      var path = button.getAttribute('data-chmod-path') || '';
      var mode = button.getAttribute('data-chmod-mode') || '';
      document.getElementById('chmodPath').value = path;
      document.getElementById('chmodMode').value = mode === '—' ? '' : mode;
      document.getElementById('chmodTarget').textContent = path;
    });
  });
})();
</script>

<dialog class="modal" id="chunkUploadDialog">
  <div class="modal__head">
    <h3>大文件分片上传</h3>
    <button class="icon-btn" type="button" data-close="chunkUploadDialog"><?= icon('x', 14) ?></button>
  </div>
  <div class="modal__body">
    <div class="field">
      <label>选择文件</label>
      <input class="input" type="file" id="chunkFileInput">
      <span class="hint">按 2 MB 切片上传，中途关闭页面后重新选择同一文件可断点续传。目标目录：<span class="mono"><?= e($rel === '' ? '根目录' : $rel) ?></span></span>
    </div>
    <div class="mt-12">
      <div class="toolbar" style="justify-content:space-between;margin-bottom:4px">
        <span class="small" id="chunkStatus">等待选择文件</span>
        <span class="small muted mono" id="chunkPercent">0%</span>
      </div>
      <div class="bar"><div class="bar__fill" id="chunkBar" style="width:0%"></div></div>
    </div>
    <p class="hint mt-12" id="chunkDetail"></p>
  </div>
  <div class="modal__foot">
    <button class="btn btn--ghost" type="button" data-close="chunkUploadDialog">关闭</button>
    <button class="btn btn--ghost" type="button" id="chunkAbort" disabled>取消上传</button>
    <button class="btn btn--primary" type="button" id="chunkStart" disabled>开始上传</button>
  </div>
</dialog>

<script>
(function () {
  var input = document.getElementById('chunkFileInput');
  var startBtn = document.getElementById('chunkStart');
  var abortBtn = document.getElementById('chunkAbort');
  var bar = document.getElementById('chunkBar');
  var percentEl = document.getElementById('chunkPercent');
  var statusEl = document.getElementById('chunkStatus');
  if (!input || !startBtn) { return; }

  var TARGET = <?= json_encode($rel, JSON_UNESCAPED_UNICODE) ?>;
  var TOKEN = <?= json_encode(csrf_token()) ?>;
  var CHUNK_SIZE = 2 * 1024 * 1024;
  var running = false;
  var aborted = false;
  var uploadId = '';

  function hashId(file) {
    var seed = file.name + '|' + file.size + '|' + (file.lastModified || 0) + '|' + TARGET;
    var h1 = 0x811c9dc5, h2 = 0x01000193, h3 = 0x9e3779b9, h4 = 0x85ebca6b;
    for (var i = 0; i < seed.length; i++) {
      var c = seed.charCodeAt(i);
      h1 = (h1 ^ c) * 16777619 >>> 0;
      h2 = (h2 + c * 31) >>> 0;
      h3 = (h3 ^ (c << 3)) * 2654435761 >>> 0;
      h4 = (h4 + (c ^ 0x5bf03635)) >>> 0;
    }
    var hex = function (n) { return ('00000000' + n.toString(16)).slice(-8); };
    return hex(h1) + hex(h2) + hex(h3) + hex(h4);
  }

  function post(url, data) {
    return fetch(url, { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (res) {
        return res.json().catch(function () {
          return { ok: false, message: '服务端返回异常（HTTP ' + res.status + '），可能页面已过期' };
        });
      });
  }

  function setProgress(done, total, text) {
    var pct = total > 0 ? Math.min(100, done / total * 100) : 0;
    bar.style.width = pct.toFixed(1) + '%';
    percentEl.textContent = pct.toFixed(1) + '%';
    if (text) { statusEl.textContent = text; }
  }

  input.addEventListener('change', function () {
    var file = input.files && input.files[0];
    startBtn.disabled = !file;
    if (!file) { return; }
    statusEl.textContent = '已选择：' + file.name;
    document.getElementById('chunkDetail').textContent =
      '大小 ' + (file.size / 1024 / 1024).toFixed(2) + ' MB · 预计 ' + Math.ceil(file.size / CHUNK_SIZE) + ' 个分片';
    setProgress(0, file.size);
  });

  startBtn.addEventListener('click', function () {
    var file = input.files && input.files[0];
    if (!file || running) { return; }
    running = true;
    aborted = false;
    startBtn.disabled = true;
    abortBtn.disabled = false;
    uploadId = hashId(file);

    var init = new FormData();
    init.append('_token', TOKEN);
    init.append('upload_id', uploadId);
    init.append('path', TARGET);
    init.append('name', file.name);
    init.append('size', file.size);
    init.append('chunk_size', CHUNK_SIZE);

    statusEl.textContent = '正在初始化…';
    post(<?= json_encode(url('/files/upload/init')) ?>, init).then(function (res) {
      if (!res.ok) { throw new Error(res.message || '初始化失败'); }
      var uploaded = res.uploaded || [];
      var total = res.total_chunks || Math.ceil(file.size / CHUNK_SIZE);
      if (uploaded.length) {
        document.getElementById('chunkDetail').textContent = '检测到已上传 ' + uploaded.length + ' 个分片，将从断点继续';
      }
      var doneBytes = 0;
      uploaded.forEach(function (index) {
        doneBytes += Math.min(CHUNK_SIZE, file.size - index * CHUNK_SIZE);
      });
      setProgress(doneBytes, file.size, '开始上传…');

      var queue = [];
      for (var i = 0; i < total; i++) {
        if (uploaded.indexOf(i) === -1) { queue.push(i); }
      }

      var uploadNext = function (pos) {
        if (aborted) { return Promise.resolve(); }
        if (pos >= queue.length) { return Promise.resolve(); }
        var index = queue[pos];
        var start = index * CHUNK_SIZE;
        var blob = file.slice(start, Math.min(start + CHUNK_SIZE, file.size));
        var form = new FormData();
        form.append('_token', TOKEN);
        form.append('upload_id', uploadId);
        form.append('index', index);
        form.append('chunk', blob, 'chunk.part');
        return post(<?= json_encode(url('/files/upload/chunk')) ?>, form).then(function (r) {
          if (!r.ok) { throw new Error(r.message || ('分片 ' + index + ' 上传失败')); }
          doneBytes += blob.size;
          setProgress(doneBytes, file.size, '已上传 ' + (pos + 1) + ' / ' + queue.length + ' 个分片');
          return uploadNext(pos + 1);
        });
      };

      return uploadNext(0).then(function () {
        if (aborted) { return; }
        statusEl.textContent = '正在合并分片…';
        var finish = new FormData();
        finish.append('_token', TOKEN);
        finish.append('upload_id', uploadId);
        finish.append('path', TARGET);
        return post(<?= json_encode(url('/files/upload/finish')) ?>, finish).then(function (r) {
          if (!r.ok) { throw new Error(r.message || '合并失败'); }
          setProgress(file.size, file.size, '上传完成：' + r.file);
          document.getElementById('chunkDetail').textContent = '已完成，正在刷新列表…';
          setTimeout(function () { window.location.reload(); }, 700);
        });
      });
    }).catch(function (err) {
      statusEl.textContent = '失败：' + err.message;
      startBtn.disabled = false;
    }).then(function () {
      running = false;
      abortBtn.disabled = true;
      if (!aborted) { startBtn.disabled = false; }
    });
  });

  abortBtn.addEventListener('click', function () {
    aborted = true;
    running = false;
    abortBtn.disabled = true;
    startBtn.disabled = false;
    statusEl.textContent = '已取消（已上传的分片会保留 12 小时，重新选择同一文件可续传）';
    var form = new FormData();
    form.append('_token', TOKEN);
    form.append('upload_id', uploadId);
    post(<?= json_encode(url('/files/upload/abort')) ?>, form);
  });
})();
</script>
