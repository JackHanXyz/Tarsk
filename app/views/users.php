<?php
declare(strict_types=1);
$statusText = ['enabled' => '启用', 'disabled' => '停用'];
$disabledCount = count(array_filter($users, fn($u) => ($u['status'] ?? 'enabled') === 'disabled'));
$operatorCount = count(array_filter($users, fn($u) => $u['role'] === 'operator'));
?>
<div class="section-head">
  <div>
    <h2>用户管理</h2>
    <p>创建账号并分配角色。角色决定可访问的页面与可执行的操作，未授权入口在侧边栏直接隐藏、直达链接返回 403。</p>
  </div>
  <div class="toolbar">
    <button class="btn btn--primary btn--sm" data-open="userAdd"><?= icon('plus', 14) ?>新建用户</button>
  </div>
</div>

<section class="stats" style="grid-template-columns:repeat(4,minmax(0,1fr))">
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon"><?= icon('user', 16) ?></span>账号总数</span></div>
    <div class="stat__value"><?= count($users) ?></div>
    <div class="stat__meta"><span>启用中的管理员</span><span><?= (int)$admin_count ?></span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--ok"><?= icon('shield', 16) ?></span>运维</span></div>
    <div class="stat__value"><?= $operatorCount ?></div>
    <div class="stat__meta"><span>无面板管理权限</span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--warn"><?= icon('eye', 16) ?></span>只读</span></div>
    <div class="stat__value"><?= count(array_filter($users, fn($u) => $u['role'] === 'viewer')) ?></div>
    <div class="stat__meta"><span>仅可浏览</span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--warn"><?= icon('lock', 16) ?></span>已停用</span></div>
    <div class="stat__value"><?= $disabledCount ?></div>
    <div class="stat__meta"><span>无法登录</span></div>
  </article>
</section>

<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('user', 17) ?>账号列表</span>
    <span class="badge badge--muted"><?= count($users) ?></span>
  </div>
  <div class="card__body card__body--flush">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr><th>账号</th><th style="width:120px">角色</th><th style="width:110px">状态</th><th>最近登录</th><th style="width:90px">两步验证</th><th style="text-align:right;width:150px">操作</th></tr>
        </thead>
        <tbody>
        <?php foreach ($users as $user): ?>
          <?php $isMe = (int)$user['id'] === (int)$me; ?>
          <tr>
            <td>
              <div class="cell-main mono"><?= e($user['username']) ?><?= $isMe ? ' <span class="badge badge--info">当前</span>' : '' ?></div>
              <div class="cell-sub">ID <?= (int)$user['id'] ?> · 创建于 <?= e(date('Y-m-d', strtotime((string)$user['created_at']))) ?></div>
            </td>
            <td>
              <form method="post" action="<?= url('/users/update') ?>" style="display:flex;gap:6px;align-items:center">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                <input type="hidden" name="status" value="<?= e($user['status'] ?? 'enabled') ?>">
                <select class="input" name="role" style="height:30px;font-size:12px;padding:0 6px" onchange="this.form.submit()" <?= $isMe ? 'disabled' : '' ?>>
                  <?php foreach ($roles as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $user['role'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td>
              <form method="post" action="<?= url('/users/update') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                <input type="hidden" name="role" value="<?= e($user['role']) ?>">
                <input type="hidden" name="status" value="<?= ($user['status'] ?? 'enabled') === 'enabled' ? 'disabled' : 'enabled' ?>">
                <button class="badge <?= ($user['status'] ?? 'enabled') === 'enabled' ? 'badge--ok' : 'badge--muted' ?>" type="submit" title="点击切换" <?= $isMe ? 'disabled' : '' ?>>
                  <i></i><?= e($statusText[$user['status'] ?? 'enabled'] ?? '启用') ?>
                </button>
              </form>
            </td>
            <td class="small">
              <?php if (!empty($user['last_login_at'])): ?>
                <div><?= e(ago((string)$user['last_login_at'])) ?></div>
                <div class="cell-sub mono"><?= e((string)($user['last_ip'] ?? '')) ?></div>
              <?php else: ?>
                <span class="muted">从未登录</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ((int)($user['totp_enabled'] ?? 0) === 1): ?>
                <span class="badge badge--ok">已开启</span>
              <?php else: ?>
                <span class="badge badge--muted">未开启</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="td-actions">
                <button class="icon-btn" type="button" title="重置密码" data-open="userPwd"
                  data-id="<?= (int)$user['id'] ?>" data-name="<?= e($user['username']) ?>"><?= icon('lock', 13) ?></button>
                <?php if (!$isMe): ?>
                  <form method="post" action="<?= url('/users/delete') ?>" data-confirm="删除用户 <?= e($user['username']) ?>？其登录能力立即失效。">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                    <button class="icon-btn icon-btn--danger" type="submit" title="删除"><?= icon('trash', 13) ?></button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card__foot"><span class="small muted">不能修改自己的角色或停用自己，且系统始终保留至少一个启用状态的管理员，避免把自己锁在门外。</span></div>
</section>

<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('shield', 17) ?>角色权限矩阵</span>
    <span class="badge badge--muted"><?= count($permission_labels) ?> 项权限</span>
  </div>
  <div class="card__body card__body--flush">
    <div class="table-wrap">
      <table class="table table--compact">
        <thead>
          <tr>
            <th>权限</th>
            <?php foreach ($roles as $role => $label): ?><th style="width:90px"><?= e($label) ?></th><?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($permission_labels as $perm => $label): ?>
          <tr>
            <td class="small"><span class="mono"><?= e($perm) ?></span><div class="cell-sub"><?= e($label) ?></div></td>
            <?php foreach ($roles as $role => $roleLabel): ?>
              <td><?= in_array($perm, $matrix[$role] ?? [], true) ? '<span class="badge badge--ok">✓</span>' : '<span class="muted">—</span>' ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<dialog class="modal" id="userAdd">
  <form method="post" action="<?= url('/users/create') ?>">
    <?= csrf_field() ?>
    <div class="modal__head">
      <h3>新建用户</h3>
      <button class="icon-btn" type="button" data-close="userAdd"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <div class="form-grid">
        <div class="field">
          <label>用户名</label>
          <input class="input mono" type="text" name="username" required pattern="[A-Za-z0-9_.\-]{3,60}" placeholder="ops01">
        </div>
        <div class="field">
          <label>初始密码</label>
          <input class="input mono" type="text" name="password" required minlength="8" placeholder="至少 8 位">
        </div>
        <div class="field span-2">
          <label>角色</label>
          <select class="input" name="role">
            <?php foreach ($roles as $value => $label): ?>
              <option value="<?= e($value) ?>" <?= $value === 'operator' ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">运维可做全部日常操作（站点/文件/数据库/终端），但不能改面板设置、管理和创建用户；只读仅能浏览。</span>
        </div>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="userAdd">取消</button>
      <button class="btn btn--primary" type="submit">创建</button>
    </div>
  </form>
</dialog>

<dialog class="modal" id="userPwd">
  <form method="post" action="<?= url('/users/password') ?>">
    <?= csrf_field() ?>
    <div class="modal__head">
      <h3>重置密码</h3>
      <button class="icon-btn" type="button" data-close="userPwd"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <div class="form-grid">
        <div class="field span-2">
          <label>账号</label>
          <input class="input mono" type="text" id="userPwdName" value="" readonly>
          <input type="hidden" name="id" id="userPwdId">
        </div>
        <div class="field span-2">
          <label>新密码</label>
          <input class="input mono" type="text" name="password" required minlength="8" placeholder="至少 8 位">
        </div>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="userPwd">取消</button>
      <button class="btn btn--primary" type="submit">更新密码</button>
    </div>
  </form>
</dialog>

<script>
(function () {
  document.querySelectorAll('[data-open="userPwd"][data-id]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('userPwdId').value = btn.getAttribute('data-id') || '';
      document.getElementById('userPwdName').value = btn.getAttribute('data-name') || '';
    });
  });
})();
</script>
