<?php
declare(strict_types=1);
?>
<div class="section-head">
  <div>
    <h2>FTP 服务</h2>
    <p>内置纯 PHP FTP 服务器，账号独立主目录，随面板一键启停。</p>
  </div>
  <div class="toolbar">
    <button class="btn btn--primary btn--sm" data-open="ftpUserModal"><?= icon('plus', 14) ?>添加账号</button>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <span class="card__title"><?= icon('download', 17) ?>服务状态</span>
    <span class="badge <?= $status['running'] ? 'badge--ok' : 'badge--muted' ?>"><i></i><?= $status['running'] ? '运行中' : '未运行' ?></span>
  </div>
  <div class="card__body">
    <dl class="kv">
      <dt>监听端口</dt><dd class="mono"><?= (int)$status['port'] ?></dd>
      <dt>进程 PID</dt><dd class="mono"><?= $status['pid'] > 0 ? (int)$status['pid'] : '—' ?></dd>
      <dt>连接示例</dt><dd class="mono">ftp://127.0.0.1:<?= (int)$status['port'] ?></dd>
      <dt>日志</dt><dd class="mono small"><?= e(storage_path('logs/ftpd.log')) ?></dd>
    </dl>
  </div>
  <div class="card__foot" style="display:flex;gap:8px;justify-content:flex-end">
    <?php if (!$status['running']): ?>
      <form method="post" action="<?= url('/ftp/service') ?>"><?= csrf_field() ?>
        <input type="hidden" name="action" value="start">
        <button class="btn btn--primary btn--sm" type="submit"><?= icon('play', 14) ?>启动</button>
      </form>
    <?php else: ?>
      <form method="post" action="<?= url('/ftp/service') ?>"><?= csrf_field() ?>
        <input type="hidden" name="action" value="restart">
        <button class="btn btn--ghost btn--sm" type="submit"><?= icon('refresh', 14) ?>重启</button>
      </form>
      <form method="post" action="<?= url('/ftp/service') ?>" data-confirm="确定停止 FTP 服务？">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="stop">
        <button class="btn btn--danger-ghost btn--sm" type="submit"><?= icon('x', 14) ?>停止</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('user', 17) ?>FTP 账号（<?= count($users) ?>）</span>
  </div>
  <div class="card__body card__body--flush">
    <?php if (!$users): ?>
      <div class="empty">
        <?= icon('user', 30) ?>
        <p>暂无 FTP 账号</p>
        <small>点击右上角「添加账号」创建</small>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>用户名</th><th>主目录</th><th>状态</th><th>创建时间</th><th style="text-align:right">操作</th></tr></thead>
          <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td class="cell-main"><?= icon('user', 14) ?><?= e($u['username']) ?></td>
              <td class="small muted mono"><?= e($u['home_dir']) ?></td>
              <td>
                <span class="badge <?= $u['status'] === 'enabled' ? 'badge--ok' : 'badge--muted' ?>"><i></i><?= $u['status'] === 'enabled' ? '启用' : '禁用' ?></span>
              </td>
              <td class="small muted"><?= e($u['created_at']) ?></td>
              <td>
                <div class="td-actions">
                  <form method="post" action="<?= url('/ftp/user/toggle') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <button class="icon-btn" type="submit" title="启用/禁用"><?= icon('refresh', 13) ?></button>
                  </form>
                  <form method="post" action="<?= url('/ftp/user/delete') ?>" data-confirm="确定删除该 FTP 账号？">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
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
</div>

<dialog class="modal" id="ftpUserModal">
  <form method="post" action="<?= url('/ftp/user/create') ?>">
    <?= csrf_field() ?>
    <div class="modal__head">
      <h3>添加 FTP 账号</h3>
      <button class="icon-btn" type="button" data-close="ftpUserModal"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <div class="form-grid">
        <div class="field span-2">
          <label>用户名</label>
          <input class="input" type="text" name="username" required minlength="3" maxlength="32" pattern="[A-Za-z0-9_.\-]+">
        </div>
        <div class="field span-2">
          <label>密码</label>
          <input class="input" type="password" name="password" required minlength="6">
        </div>
        <div class="field span-2">
          <label>主目录（相对站点根目录，留空为整个站点根目录）</label>
          <input class="input mono" type="text" name="home_dir" placeholder="例如 mysite">
          <span class="hint">站点根目录：<span class="mono"><?= e($sitesRoot) ?></span></span>
        </div>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="ftpUserModal">取消</button>
      <button class="btn btn--primary" type="submit"><?= icon('check', 14) ?>创建</button>
    </div>
  </form>
</dialog>
