<?php
declare(strict_types=1);
$endpoints = [
    ['GET', '/api/v1/status', 'read', '面板版本、主机名、CPU/内存/磁盘、站点数、Nginx 状态'],
    ['GET', '/api/v1/sites', 'read', '站点列表（id/域名/状态/PHP/SSL）'],
    ['POST', '/api/v1/sites/{id}/toggle', 'write', '启停站点并重写 vhost'],
    ['GET', '/api/v1/databases', 'read', '数据库列表（表数量 / 占用字节）'],
    ['GET', '/api/v1/backups', 'read', '备份文件列表'],
    ['POST', '/api/v1/backups/site', 'write', '创建站点备份（body: {"site_id":1}）'],
    ['POST', '/api/v1/backups/db', 'write', '创建数据库备份（body: {"database":"x"}）'],
    ['GET', '/api/v1/tasks', 'read', '计划任务列表'],
    ['POST', '/api/v1/tasks/{id}/run', 'write', '立即执行任务'],
    ['GET', '/api/v1/alerts', 'read', '最近告警（?limit=20）'],
    ['GET', '/api/v1/nginx', 'read', 'Nginx 运行状态与配置检测'],
    ['POST', '/api/v1/nginx/reload', 'write', '重载 Nginx'],
];
?>
<div class="section-head">
  <div>
    <h2>API Token</h2>
    <p>用于外部系统调用面板接口。Token 只在创建时显示一次，服务端只保存 SHA-256 摘要。</p>
  </div>
  <div class="toolbar">
    <button class="btn btn--primary btn--sm" data-open="tokenAdd"><?= icon('plus', 14) ?>新建 Token</button>
  </div>
</div>

<?php if ($created !== ''): ?>
<div class="alert alert--success">
  <?= icon('check', 16) ?>
  <span>
    新 Token 已生成，请立即复制（关闭后无法再次查看）：<br>
    <code class="mono" style="font-size:13px;word-break:break-all"><?= e($created) ?></code>
  </span>
</div>
<?php endif; ?>

<section class="card">
  <div class="card__head">
    <span class="card__title"><?= icon('lock', 17) ?>已有 Token</span>
    <span class="badge badge--muted"><?= count($tokens) ?></span>
  </div>
  <div class="card__body card__body--flush">
    <?php if (!$tokens): ?>
      <div class="empty">
        <?= icon('lock', 30) ?>
        <p>还没有 API Token</p>
        <small>创建后可用 <code class="mono">Authorization: Bearer tk_...</code> 调用 /api/v1 接口</small>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>名称</th><th>前缀</th><th>权限</th><th>有效期</th><th>最近使用</th><th style="width:70px"></th></tr></thead>
          <tbody>
          <?php foreach ($tokens as $token): ?>
            <tr>
              <td class="cell-main">
                <?= e($token['name']) ?>
                <?php if ($token['expired']): ?><span class="badge badge--danger">已过期</span><?php endif; ?>
              </td>
              <td class="mono small"><?= e($token['token_prefix']) ?>…</td>
              <td><span class="badge <?= $token['scopes'] === 'write' ? 'badge--warn' : 'badge--info' ?>"><?= e($token['scopes']) ?></span></td>
              <td class="small"><?= $token['expires_at'] ? e(date('Y-m-d', strtotime((string)$token['expires_at']))) : '长期有效' ?></td>
              <td class="small">
                <?php if (!empty($token['last_used_at'])): ?>
                  <div><?= e(ago((string)$token['last_used_at'])) ?></div>
                  <div class="cell-sub mono"><?= e((string)($token['last_ip'] ?? '')) ?></div>
                <?php else: ?>
                  <span class="muted">从未使用</span>
                <?php endif; ?>
              </td>
              <td class="td-actions">
                <form method="post" action="<?= url('/api/delete') ?>" data-confirm="吊销 Token「<?= e($token['name']) ?>」？使用它的调用会立即失效。">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int)$token['id'] ?>">
                  <button class="icon-btn icon-btn--danger" type="submit" title="吊销"><?= icon('trash', 13) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('list', 17) ?>可用接口</span>
    <span class="badge badge--muted"><?= count($endpoints) ?> 个</span>
  </div>
  <div class="card__body card__body--flush">
    <div class="table-wrap">
      <table class="table table--compact">
        <thead><tr><th style="width:70px">方法</th><th>路径</th><th style="width:70px">权限</th><th>说明</th></tr></thead>
        <tbody>
        <?php foreach ($endpoints as [$method, $url, $scope, $desc]): ?>
          <tr>
            <td><span class="badge <?= $method === 'GET' ? 'badge--info' : 'badge--warn' ?>"><?= e($method) ?></span></td>
            <td class="mono small"><?= e($url) ?></td>
            <td class="small"><?= e($scope) ?></td>
            <td class="small muted"><?= e($desc) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card__foot">
    <span class="small muted">
      调用示例：<code class="mono">curl -H "Authorization: Bearer tk_xxx" <?= e($base_url) ?>/api/v1/status</code>
      · 变更类接口用 JSON body，返回统一为 <code class="mono">{"ok":true,"data":...}</code>
    </span>
  </div>
</section>

<dialog class="modal" id="tokenAdd">
  <form method="post" action="<?= url('/api/create') ?>">
    <?= csrf_field() ?>
    <div class="modal__head">
      <h3>新建 API Token</h3>
      <button class="icon-btn" type="button" data-close="tokenAdd"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <div class="form-grid">
        <div class="field span-2">
          <label>用途名称</label>
          <input class="input" type="text" name="name" placeholder="例如：监控告警机器人" maxlength="120">
        </div>
        <div class="field">
          <label>权限范围</label>
          <select class="input" name="scope">
            <?php foreach ($scopes as $value => $label): ?>
              <option value="<?= e($value) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>有效期（天）</label>
          <input class="input mono" type="number" name="days" min="0" max="3650" value="0">
          <span class="hint">0 表示长期有效</span>
        </div>
      </div>
      <div class="alert alert--warning mt-12">
        <?= icon('alert', 16) ?>
        <span>Token 等同于你的账号权限，请勿写入前端代码或公开仓库。</span>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="tokenAdd">取消</button>
      <button class="btn btn--primary" type="submit">生成</button>
    </div>
  </form>
</dialog>
