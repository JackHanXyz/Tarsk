<?php
declare(strict_types=1);
?>
<div class="section-head">
  <div>
    <h2>SSL 证书</h2>
    <p>为站点签发自签名证书或导入已有证书，证书路径会写入对应 Nginx 配置。</p>
  </div>
  <div class="toolbar">
    <button class="btn btn--ghost btn--sm" data-open="sslUpload"><?= icon('upload', 14) ?>导入证书</button>
    <button class="btn btn--primary btn--sm" data-open="sslGenerate" <?= $openssl_ext ? '' : 'disabled' ?>><?= icon('lock', 14) ?>签发证书</button>
  </div>
</div>

<?php if (!$openssl_ext): ?>
  <div class="alert alert--error">
    <?= icon('alert', 16) ?>
    <span>当前 PHP 未启用 <code>openssl</code> 扩展，无法生成或解析证书。请在 php.ini 中启用 extension=openssl 后重启 PHP。</span>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card__head">
    <span class="card__title"><?= icon('lock', 17) ?>证书列表<span class="card__desc">共 <?= count($certs) ?> 张</span></span>
    <span class="badge <?= $openssl_ext ? 'badge--ok' : 'badge--warn' ?>"><i></i>OpenSSL <?= $openssl_ext ? '可用' : '不可用' ?></span>
  </div>
  <div class="card__body card__body--flush">
    <?php if (!$certs): ?>
      <div class="empty">
        <?= icon('lock', 30) ?>
        <p>还没有证书</p>
        <small>可为站点生成自签名证书，或粘贴已有 PEM 证书与私钥</small>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>域名</th><th>关联站点</th><th>签发者</th><th>到期时间</th><th>剩余</th><th>来源</th><th style="text-align:right">操作</th></tr></thead>
          <tbody>
          <?php foreach ($certs as $cert): ?>
            <tr>
              <td class="cell-main mono"><?= e($cert['domain']) ?></td>
              <td><?= $cert['site_name'] ? e($cert['site_name']) : '<span class="muted">未关联</span>' ?></td>
              <td class="small"><?= e($cert['issuer'] ?: '—') ?></td>
              <td class="small mono"><?= e($cert['not_after'] ? date('Y-m-d', strtotime($cert['not_after'])) : '—') ?></td>
              <td>
                <?php if ($cert['days_left'] === null): ?>
                  <span class="muted">—</span>
                <?php elseif ($cert['days_left'] < 0): ?>
                  <span class="badge badge--danger">已过期</span>
                <?php elseif ($cert['days_left'] < 30): ?>
                  <span class="badge badge--warn"><?= (int)$cert['days_left'] ?> 天</span>
                <?php else: ?>
                  <span class="badge badge--ok"><?= (int)$cert['days_left'] ?> 天</span>
                <?php endif; ?>
              </td>
              <td><span class="badge badge--muted"><?= $cert['source'] === 'self' ? '自签名' : '导入' ?></span></td>
              <td>
                <div class="td-actions">
                  <form method="post" action="<?= url('/ssl/' . (int)$cert['id'] . '/delete') ?>" data-confirm="确定删除证书 <?= e($cert['domain']) ?>？">
                    <?= csrf_field() ?>
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
  <div class="card__foot">
    <span class="small muted mono">证书存储目录：<?= e($ssl_dir) ?></span>
  </div>
</div>

<dialog class="modal" id="sslGenerate">
  <form method="post" action="<?= url('/ssl/generate') ?>">
    <?= csrf_field() ?>
    <div class="modal__head">
      <h3>签发自签名证书</h3>
      <button class="icon-btn" type="button" data-close="sslGenerate"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <div class="form-grid">
        <div class="field span-2">
          <label>关联站点（可选）</label>
          <select class="input" name="site_id">
            <option value="0">不关联站点</option>
            <?php foreach ($sites as $site): ?>
              <option value="<?= (int)$site['id'] ?>"><?= e($site['name']) ?><?= $site['domain'] ? ' · ' . e($site['domain']) : '' ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">关联后会自动更新站点 Nginx 配置并启用 HTTPS。</span>
        </div>
        <div class="field">
          <label>域名 / Common Name</label>
          <input class="input" type="text" name="domain" placeholder="example.com">
        </div>
        <div class="field">
          <label>有效期（天）</label>
          <input class="input" type="number" name="days" value="365" min="1" max="3650">
        </div>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="sslGenerate">取消</button>
      <button class="btn btn--primary" type="submit"><?= icon('lock', 14) ?>生成证书</button>
    </div>
  </form>
</dialog>

<dialog class="modal modal--wide" id="sslUpload">
  <form method="post" action="<?= url('/ssl/upload') ?>">
    <?= csrf_field() ?>
    <div class="modal__head">
      <h3>导入证书</h3>
      <button class="icon-btn" type="button" data-close="sslUpload"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <div class="form-grid">
        <div class="field">
          <label>域名</label>
          <input class="input" type="text" name="domain" placeholder="example.com" required>
        </div>
        <div class="field">
          <label>关联站点</label>
          <select class="input" name="site_id">
            <option value="0">不关联站点</option>
            <?php foreach ($sites as $site): ?>
              <option value="<?= (int)$site['id'] ?>"><?= e($site['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field span-2">
          <label>证书内容（PEM）</label>
          <textarea class="input" name="cert" rows="6" placeholder="-----BEGIN CERTIFICATE-----" required style="font-family:var(--mono);font-size:12px"></textarea>
        </div>
        <div class="field span-2">
          <label>私钥内容（PEM）</label>
          <textarea class="input" name="key" rows="6" placeholder="-----BEGIN PRIVATE KEY-----" required style="font-family:var(--mono);font-size:12px"></textarea>
        </div>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="sslUpload">取消</button>
      <button class="btn btn--primary" type="submit"><?= icon('upload', 14) ?>导入证书</button>
    </div>
  </form>
</dialog>
