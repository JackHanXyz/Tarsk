<?php
declare(strict_types=1);
$kindBadge = ['s3' => 'badge--info', 'oss' => 'badge--warn', 'webdav' => 'badge--muted'];
?>
<div class="section-head">
  <div>
    <h2>异地备份</h2>
    <p>把本地备份文件推送到 S3 / 阿里云 OSS / WebDAV，实现异地留档。签名在面板本地计算，密钥不会外发。</p>
  </div>
  <div class="toolbar">
    <a class="btn btn--ghost btn--sm" href="<?= url('/backups') ?>"><?= icon('save', 14) ?>本地备份</a>
    <button class="btn btn--primary btn--sm" data-open="remoteAdd"><?= icon('plus', 14) ?>新增目标</button>
  </div>
</div>

<section class="card">
  <div class="card__head">
    <span class="card__title"><?= icon('globe', 17) ?>备份目标</span>
    <span class="badge badge--muted"><?= count($targets) ?></span>
  </div>
  <div class="card__body card__body--flush">
    <?php if (!$targets): ?>
      <div class="empty">
        <?= icon('globe', 30) ?>
        <p>还没有配置异地备份目标</p>
        <small>支持兼容 S3 的对象存储、阿里云 OSS 与 WebDAV（坚果云 / Nextcloud / 群晖）</small>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>名称</th><th>类型</th><th>Endpoint / 目录</th><th>上次结果</th><th style="text-align:right;width:150px">操作</th></tr></thead>
          <tbody>
          <?php foreach ($targets as $target): ?>
            <tr>
              <td class="cell-main"><?= e($target['name']) ?><div class="cell-sub mono"><?= e($target['bucket'] ?: '—') ?></div></td>
              <td><span class="badge <?= e($kindBadge[$target['kind']] ?? 'badge--muted') ?>"><?= e($target['kind']) ?></span></td>
              <td class="small mono" style="word-break:break-all">
                <?= e($target['endpoint']) ?>
                <div class="cell-sub">/<?= e($target['remote_dir'] ?: '') ?></div>
              </td>
              <td class="small">
                <?php if (!empty($target['last_at'])): ?>
                  <div><?= e(ago((string)$target['last_at'])) ?></div>
                  <div class="cell-sub" title="<?= e((string)$target['last_result']) ?>"><?= e(mb_strimwidth((string)$target['last_result'], 0, 40, '…')) ?></div>
                <?php else: ?>
                  <span class="muted">未测试</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="td-actions">
                  <form method="post" action="<?= url('/backups/remote/test') ?>" data-busy="正在测试连通性…">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$target['id'] ?>">
                    <button class="icon-btn" type="submit" title="测试连接"><?= icon('activity', 13) ?></button>
                  </form>
                  <button class="icon-btn" type="button" title="编辑" data-open="remoteAdd"
                    data-id="<?= (int)$target['id'] ?>"
                    data-name="<?= e($target['name']) ?>"
                    data-kind="<?= e($target['kind']) ?>"
                    data-endpoint="<?= e($target['endpoint']) ?>"
                    data-region="<?= e($target['region']) ?>"
                    data-bucket="<?= e($target['bucket']) ?>"
                    data-dir="<?= e($target['remote_dir']) ?>"
                    data-ak="<?= e($target['access_key']) ?>"><?= icon('edit', 13) ?></button>
                  <form method="post" action="<?= url('/backups/remote/delete') ?>" data-confirm="删除目标「<?= e($target['name']) ?>」？远端已有文件不会被删除。">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$target['id'] ?>">
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
</section>

<?php if ($targets): ?>
<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('upload', 17) ?>上传本地备份</span>
    <span class="badge badge--muted"><?= count($backups) ?> 个文件</span>
  </div>
  <form method="post" action="<?= url('/backups/remote/upload') ?>" data-busy="正在上传，大文件请耐心等待…">
    <?= csrf_field() ?>
    <div class="card__body">
      <?php if (!$backups): ?>
        <div class="empty" style="padding:20px">还没有本地备份文件，先到「备份管理」创建</div>
      <?php else: ?>
        <div class="form-grid">
          <div class="field">
            <label>目标</label>
            <select class="input" name="id">
              <?php foreach ($targets as $target): ?>
                <option value="<?= (int)$target['id'] ?>"><?= e($target['name']) ?>（<?= e($target['kind']) ?>）</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>备份文件</label>
            <select class="input" name="file">
              <?php foreach (array_slice($backups, 0, 30) as $backup): ?>
                <option value="<?= e($backup['name']) ?>"><?= e($backup['name']) ?> · <?= bytes($backup['size']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <?php if ($backups): ?>
    <div class="card__foot" style="display:flex;justify-content:flex-end">
      <button class="btn btn--primary btn--sm" type="submit"><?= icon('upload', 14) ?>上传到远端</button>
    </div>
    <?php endif; ?>
  </form>
</section>
<?php endif; ?>

<dialog class="modal" id="remoteAdd">
  <form method="post" action="<?= url('/backups/remote/save') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id" id="remoteId" value="0">
    <div class="modal__head">
      <h3 id="remoteTitle">新增备份目标</h3>
      <button class="icon-btn" type="button" data-close="remoteAdd"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <div class="form-grid">
        <div class="field">
          <label>名称</label>
          <input class="input" type="text" name="name" id="remoteName" required placeholder="例如：阿里云 OSS 归档">
        </div>
        <div class="field">
          <label>类型</label>
          <select class="input" name="kind" id="remoteKind">
            <?php foreach ($kinds as $value => $label): ?>
              <option value="<?= e($value) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field span-2">
          <label>Endpoint</label>
          <input class="input mono" type="text" name="endpoint" id="remoteEndpoint" required placeholder="s3.amazonaws.com / oss-cn-hangzhou.aliyuncs.com / https://dav.jianguoyun.com/dav">
          <span class="hint">S3 填域名（可带 https://）；OSS 填 oss-&lt;region&gt;.aliyuncs.com；WebDAV 填完整基础 URL。</span>
        </div>
        <div class="field">
          <label>Region</label>
          <input class="input mono" type="text" name="region" id="remoteRegion" placeholder="S3 必填，如 ap-east-1">
        </div>
        <div class="field">
          <label>Bucket / 远端目录</label>
          <input class="input mono" type="text" name="bucket" id="remoteBucket" placeholder="S3/OSS 填 bucket 名">
        </div>
        <div class="field">
          <label>对象前缀目录</label>
          <input class="input mono" type="text" name="remote_dir" id="remoteDir" value="tarsk">
        </div>
        <div class="field">
          <label>Access Key / 用户名</label>
          <input class="input mono" type="text" name="access_key" id="remoteAk" placeholder="WebDAV 填账号">
        </div>
        <div class="field">
          <label>Secret Key / 密码</label>
          <input class="input mono" type="text" name="secret_key" placeholder="编辑时留空表示不修改">
        </div>
      </div>
      <div class="alert alert--warning mt-12">
        <?= icon('alert', 16) ?>
        <span>密钥以明文存于面板数据库，请确保面板本身只有管理员可访问。</span>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="remoteAdd">取消</button>
      <button class="btn btn--primary" type="submit">保存</button>
    </div>
  </form>
</dialog>

<script>
(function () {
  document.querySelectorAll('[data-open="remoteAdd"][data-id]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var map = {
        remoteId: btn.getAttribute('data-id'),
        remoteName: btn.getAttribute('data-name'),
        remoteKind: btn.getAttribute('data-kind'),
        remoteEndpoint: btn.getAttribute('data-endpoint'),
        remoteRegion: btn.getAttribute('data-region'),
        remoteBucket: btn.getAttribute('data-bucket'),
        remoteDir: btn.getAttribute('data-dir'),
        remoteAk: btn.getAttribute('data-ak')
      };
      Object.keys(map).forEach(function (id) {
        var el = document.getElementById(id);
        if (el && map[id] !== null) { el.value = map[id]; }
      });
      var title = document.getElementById('remoteTitle');
      if (title) { title.textContent = '编辑备份目标'; }
    });
  });
})();
</script>
