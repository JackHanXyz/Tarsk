<?php
declare(strict_types=1);
$labels = authz_roles();
?>
<div class="section-head">
  <div>
    <h2>权限不足</h2>
    <p><?= e($message ?? '当前账号没有访问该页面的权限。') ?></p>
  </div>
  <div class="toolbar">
    <a class="btn btn--ghost btn--sm" href="<?= url('/') ?>"><?= icon('grid', 14) ?>返回概览</a>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <span class="card__title"><?= icon('shield', 17) ?>当前角色权限</span>
    <span class="badge <?= e($role ?? '') === 'admin' ? 'badge--ok' : 'badge--warn' ?>"><?= e($labels[$role ?? ''] ?? ($role ?? '')) ?></span>
  </div>
  <div class="card__body">
    <p class="small muted">如果你认为这是误判，请联系管理员在「用户管理」里调整你的角色。</p>
  </div>
</div>
