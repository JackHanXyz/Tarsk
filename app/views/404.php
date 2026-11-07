<?php
declare(strict_types=1);
?>
<div class="card">
  <div class="empty" style="padding:70px 20px">
    <?= icon('alert', 36) ?>
    <p style="font-size:15px;color:var(--text)">页面不存在</p>
    <small>你访问的地址没有对应的处理逻辑，请检查链接是否正确。</small>
    <div class="toolbar" style="justify-content:center;margin-top:18px">
      <a class="btn btn--primary btn--sm" href="<?= url('/') ?>">返回概览</a>
      <a class="btn btn--ghost btn--sm" href="<?= url('/logs') ?>">查看日志</a>
    </div>
  </div>
</div>
