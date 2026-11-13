<?php
declare(strict_types=1);
$dbTabs = [
    ['key' => 'list', 'label' => '数据库', 'url' => '/databases', 'icon' => 'database'],
    ['key' => 'users', 'label' => '用户与权限', 'url' => '/databases/users', 'icon' => 'user'],
    ['key' => 'slow', 'label' => '慢查询报表', 'url' => '/databases/slow', 'icon' => 'activity'],
    ['key' => 'replication', 'label' => '主从与同步', 'url' => '/databases/replication', 'icon' => 'network'],
];
$remoteCount = count(array_filter($users, fn($u) => $u['remote']));
?>
<div class="section-head">
  <div>
    <h2>数据库用户与权限</h2>
    <p>创建账号、按库授权、切换远程访问。所有操作走管理账号执行，内置账号（root 等）不可删除。</p>
  </div>
  <div class="toolbar">
    <?php if ($version !== ''): ?><span class="badge badge--info">MySQL <?= e($version) ?></span><?php endif; ?>
    <button class="btn btn--primary btn--sm" data-open="userCreate"><?= icon('plus', 14) ?>新建用户</button>
  </div>
</div>

<div class="toolbar" style="margin-bottom:14px;flex-wrap:wrap">
  <?php foreach ($dbTabs as $tab): ?>
    <a class="btn btn--sm <?= $tab['key'] === 'users' ? 'btn--primary' : 'btn--ghost' ?>" href="<?= url($tab['url']) ?>"><?= icon($tab['icon'], 14) ?><?= e($tab['label']) ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$connected): ?>
  <div class="alert alert--error">
    <?= icon('alert', 16) ?>
    <span>无法连接 MySQL，请先在<a href="<?= url('/settings') ?>">面板设置</a>中配置具备管理权限的账号。</span>
  </div>
<?php else: ?>

<?php if ($list_error !== ''): ?>
  <div class="alert alert--error">
    <?= icon('alert', 16) ?>
    <span>
      <?= e($list_error) ?>。<br>
      用户管理需要全局权限，请在<a href="<?= url('/settings') ?>">面板设置</a>里把「MySQL 管理账号」改成 root（或具备
      <code class="mono">CREATE USER</code> / <code class="mono">SELECT ON mysql.*</code> 的账号）后重试。
    </span>
  </div>
<?php elseif (!$can_manage): ?>
  <div class="alert alert--warning">
    <?= icon('alert', 16) ?>
    <span>当前账号没有全局权限，创建用户、切换远程访问与调整运行参数都会失败。建议在<a href="<?= url('/settings') ?>">面板设置</a>中改用 root 账号。</span>
  </div>
<?php endif; ?>

<?php if ($grants): ?>
<div class="card">
  <div class="card__head">
    <span class="card__title"><?= icon('lock', 17) ?>当前管理账号授权</span>
    <span class="badge <?= $can_manage ? 'badge--ok' : 'badge--warn' ?>"><?= $can_manage ? '具备全局权限' : '仅局部权限' ?></span>
  </div>
  <div class="card__body">
    <pre class="pre pre--light" style="max-height:160px;overflow:auto"><?= e(implode("\n", $grants)) ?></pre>
  </div>
</div>
<?php endif; ?>

<section class="stats" style="grid-template-columns:repeat(3,minmax(0,1fr))">
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon"><?= icon('user', 16) ?></span>账号总数</span></div>
    <div class="stat__value"><?= count($users) ?></div>
    <div class="stat__meta"><span>可管理数据库</span><span><?= count($databases) ?></span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--warn"><?= icon('globe', 16) ?></span>允许远程</span><span class="badge badge--muted">host=%</span></div>
    <div class="stat__value"><?= $remoteCount ?></div>
    <div class="stat__meta"><span>仅本机</span><span><?= count($users) - $remoteCount ?></span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--ok"><?= icon('shield', 16) ?></span>内置账号</span><span class="badge badge--muted">受保护</span></div>
    <div class="stat__value"><?= count(array_filter($users, fn($u) => $u['protected'])) ?></div>
    <div class="stat__meta"><span>不可删除 / 不可切远程</span></div>
  </article>
</section>

<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('user', 17) ?>账号列表</span>
    <span class="badge badge--muted"><?= count($users) ?></span>
  </div>
  <div class="card__body card__body--flush">
    <?php if (!$users): ?>
      <div class="empty">
        <?= icon('user', 30) ?>
        <p>没有可显示的账号</p>
        <small>可能当前管理账号缺少读取 mysql.user 的权限</small>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>账号</th><th>授权范围</th><th>连接来源</th><th style="text-align:right">操作</th></tr></thead>
          <tbody>
          <?php foreach ($users as $user): ?>
            <tr>
              <td>
                <div class="cell-main mono"><?= e($user['user']) ?><span class="muted">@</span><?= e($user['host']) ?></div>
                <?php if ($user['protected']): ?><span class="badge badge--warn">内置</span><?php endif; ?>
                <?php if ($user['remote']): ?><span class="badge badge--danger">远程</span><?php endif; ?>
              </td>
              <td class="small">
                <?php if ($user['dbs']): ?>
                  <?php foreach (array_slice($user['dbs'], 0, 4) as $db): ?>
                    <span class="badge badge--muted mono"><?= e($db) ?></span>
                  <?php endforeach; ?>
                  <?php if (count($user['dbs']) > 4): ?><span class="hint">+<?= count($user['dbs']) - 4 ?></span><?php endif; ?>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
                <?php if ($user['grants']): ?>
                  <details>
                    <summary class="cell-sub" style="cursor:pointer">查看授权（<?= count($user['grants']) ?>）</summary>
                    <pre class="pre pre--light mt-12" style="max-height:180px;overflow:auto"><?= e(implode("\n", $user['grants'])) ?></pre>
                  </details>
                <?php endif; ?>
              </td>
              <td class="small">
                <?php if ($user['remote']): ?>
                  <span class="badge badge--danger">%</span> 任意主机
                <?php else: ?>
                  <span class="badge badge--ok">本机</span> <?= e($user['host']) ?>
                <?php endif; ?>
              </td>
              <td>
                <div class="td-actions">
                  <form method="post" action="<?= url('/databases/users/remote') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user" value="<?= e($user['user']) ?>">
                    <input type="hidden" name="host" value="<?= e($user['host']) ?>">
                    <input type="hidden" name="remote" value="<?= $user['remote'] ? '' : '1' ?>">
                    <button class="icon-btn" type="submit" title="<?= $user['remote'] ? '改为仅本机访问' : '允许远程访问' ?>" <?= $user['protected'] ? 'disabled' : '' ?>><?= icon('globe', 13) ?></button>
                  </form>
                  <button class="icon-btn" type="button" title="重置密码"
                    data-open="userPass"
                    data-user="<?= e($user['user']) ?>"
                    data-host="<?= e($user['host']) ?>"><?= icon('lock', 13) ?></button>
                  <?php if (!$user['protected']): ?>
                    <form method="post" action="<?= url('/databases/users/drop') ?>" data-confirm="删除用户 <?= e($user['user']) ?>@<?= e($user['host']) ?>？其授权会一并移除，不可恢复。">
                      <?= csrf_field() ?>
                      <input type="hidden" name="user" value="<?= e($user['user']) ?>">
                      <input type="hidden" name="host" value="<?= e($user['host']) ?>">
                      <button class="icon-btn icon-btn--danger" type="submit" title="删除用户"><?= icon('trash', 13) ?></button>
                    </form>
                  <?php endif; ?>
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
    <span class="small muted">提示：远程访问使用 <code class="mono">%</code> 作为 host，开放前请确认云服务器安全组与防火墙已限制来源 IP。</span>
  </div>
</section>

<dialog class="modal" id="userCreate">
  <form method="post" action="<?= url('/databases/users/create') ?>">
    <?= csrf_field() ?>
    <div class="modal__head">
      <h3>新建数据库用户</h3>
      <button class="icon-btn" type="button" data-close="userCreate"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <div class="form-grid">
        <div class="field">
          <label>用户名</label>
          <input class="input mono" type="text" name="user" required pattern="[A-Za-z0-9_][A-Za-z0-9_.\-]{0,31}" placeholder="site_user">
        </div>
        <div class="field">
          <label>密码</label>
          <input class="input mono" type="text" name="password" required minlength="6" placeholder="至少 6 位">
        </div>
        <div class="field">
          <label>权限范围</label>
          <select class="input" name="privileges">
            <option value="ALL PRIVILEGES">全部权限</option>
            <option value="SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER">读写 + 结构变更</option>
            <option value="SELECT, INSERT, UPDATE, DELETE">仅读写数据</option>
            <option value="SELECT">只读</option>
          </select>
        </div>
        <div class="field">
          <label>连接来源</label>
          <select class="input" name="remote">
            <option value="">仅本机（localhost）</option>
            <option value="1">允许远程（%）</option>
          </select>
        </div>
        <div class="field span-2">
          <label>授权数据库（可多选）</label>
          <div class="form-grid" style="gap:6px">
            <?php foreach ($databases as $db): ?>
              <label class="checkbox"><input type="checkbox" name="databases[]" value="<?= e($db) ?>"> <span class="mono"><?= e($db) ?></span></label>
            <?php endforeach; ?>
          </div>
          <?php if (!$databases): ?><span class="hint">还没有可授权的业务数据库</span><?php endif; ?>
        </div>
      </div>
      <p class="small muted mt-12">账号创建后不会自动写进站点配置，需要在站点里手动填写连接信息。</p>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="userCreate">取消</button>
      <button class="btn btn--primary" type="submit">创建</button>
    </div>
  </form>
</dialog>

<dialog class="modal" id="userPass">
  <form method="post" action="<?= url('/databases/users/password') ?>">
    <?= csrf_field() ?>
    <div class="modal__head">
      <h3>重置密码</h3>
      <button class="icon-btn" type="button" data-close="userPass"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <div class="form-grid">
        <div class="field span-2">
          <label>账号</label>
          <input class="input mono" type="text" name="account" id="userPassAccount" value="" readonly>
          <input type="hidden" name="user" id="userPassUser">
          <input type="hidden" name="host" id="userPassHost">
        </div>
        <div class="field span-2">
          <label>新密码</label>
          <input class="input mono" type="text" name="password" required minlength="6" placeholder="至少 6 位">
        </div>
      </div>
      <p class="hint mt-12">修改后使用旧密码的连接会立即失效，请同步更新站点配置。</p>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="userPass">取消</button>
      <button class="btn btn--primary" type="submit">更新密码</button>
    </div>
  </form>
</dialog>

<script>
(function () {
  document.querySelectorAll('[data-open="userPass"][data-user]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var user = btn.getAttribute('data-user') || '';
      var host = btn.getAttribute('data-host') || '';
      var label = document.getElementById('userPassAccount');
      var u = document.getElementById('userPassUser');
      var h = document.getElementById('userPassHost');
      if (label) { label.value = user + '@' + host; }
      if (u) { u.value = user; }
      if (h) { h.value = host; }
    });
  });
})();
</script>

<?php endif; ?>
