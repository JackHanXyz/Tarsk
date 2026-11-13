<?php
declare(strict_types=1);
?>
<div class="auth-card">
  <h2>两步验证</h2>
  <p class="muted">请输入验证器 App 中显示的 6 位动态验证码；手机丢失时可改用一次性恢复码。</p>
  <?php foreach (flash_messages() as $flash): ?>
    <div class="alert alert--<?= e($flash['type']) ?>">
      <span><?= e($flash['message']) ?></span>
    </div>
  <?php endforeach; ?>
  <form method="post" action="<?= url('/login/2fa') ?>">
    <?= csrf_field() ?>
    <label class="field">
      <span class="field__label">动态验证码 / 恢复码</span>
      <input type="text" name="code" inputmode="text" maxlength="13" autocomplete="one-time-code" required autofocus placeholder="000000" style="letter-spacing:6px;text-align:center;font-size:20px">
    </label>
    <button class="btn btn--primary btn--block" type="submit">验证并登录</button>
  </form>
  <p class="small muted" style="margin-top:14px"><a href="<?= url('/login') ?>">返回重新登录</a></p>
</div>
