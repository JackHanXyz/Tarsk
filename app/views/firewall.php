<?php
declare(strict_types=1);
?>
<div class="section-head">
  <div>
    <h2>防火墙</h2>
    <p>管理面板创建的端口规则，并同步写入系统防火墙。</p>
  </div>
  <div class="toolbar">
    <button class="btn btn--primary btn--sm" data-open="fwCreate"><?= icon('plus', 14) ?>新增规则</button>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <span class="card__title"><?= icon('shield', 17) ?>系统防火墙状态</span>
    <?php if ($status['supported']): ?>
      <span class="badge badge--ok"><i></i>可读取</span>
    <?php else: ?>
      <span class="badge badge--warn"><i></i>不可用</span>
    <?php endif; ?>
  </div>
  <div class="card__body">
    <?php if (!$status['supported']): ?>
      <div class="alert alert--warning" style="margin:0">
        <?= icon('alert', 16) ?>
        <span>未能读取系统防火墙配置。Windows 下请确认 PowerShell 可用；Linux 下请确认已安装 ufw。写入规则通常需要管理员权限。</span>
      </div>
    <?php else: ?>
      <div class="grid grid--3" style="gap:12px">
        <?php foreach ($status['profiles'] as $profile): ?>
          <div class="service-row" style="border:1px solid var(--line-soft);border-radius:10px;padding:12px 14px;border-bottom:1px solid var(--line-soft)">
            <div class="svc">
              <span class="dot <?= $profile['enabled'] ? 'dot--ok' : 'dot--off' ?>"></span>
              <strong><?= e($profile['name']) ?></strong>
            </div>
            <span class="badge <?= $profile['enabled'] ? 'badge--ok' : 'badge--muted' ?>"><?= $profile['enabled'] ? '已启用' : '已关闭' ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <span class="card__title"><?= icon('shield', 17) ?>Tarsk 规则<span class="card__desc">共 <?= count($rules) ?> 条</span></span>
  </div>
  <div class="card__body card__body--flush">
    <?php if (!$rules): ?>
      <div class="empty">
        <?= icon('shield', 30) ?>
        <p>还没有自定义规则</p>
        <small>面板仅展示由 Tarsk 创建的规则，避免误操作其他系统规则</small>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr><th>名称</th><th>方向</th><th>动作</th><th>协议 / 端口</th><th>来源 IP</th><th>写入结果</th><th style="text-align:right">操作</th></tr>
          </thead>
          <tbody>
          <?php foreach ($rules as $rule): ?>
            <tr>
              <td class="cell-main"><?= e($rule['name']) ?></td>
              <td><span class="badge badge--muted"><?= $rule['direction'] === 'inbound' ? '入站' : '出站' ?></span></td>
              <td><span class="badge <?= $rule['action'] === 'allow' ? 'badge--ok' : 'badge--danger' ?>"><?= $rule['action'] === 'allow' ? '允许' : '阻止' ?></span></td>
              <td class="mono small"><?= strtoupper(e($rule['protocol'])) ?> <?= e($rule['port'] ?: '全部') ?></td>
              <td class="mono small"><?= e($rule['remote_ip']) ?></td>
              <td>
                <?php if ($rule['error'] !== ''): ?>
                  <span class="badge badge--warn" title="<?= e($rule['error']) ?>">写入失败</span>
                  <div class="cell-sub" title="<?= e($rule['error']) ?>"><?= e(mb_substr($rule['error'], 0, 30)) ?></div>
                <?php else: ?>
                  <span class="badge badge--ok">已写入</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="td-actions">
                  <form method="post" action="<?= url('/firewall/' . (int)$rule['id'] . '/delete') ?>" data-confirm="确定删除规则「<?= e($rule['name']) ?>」？">
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
    <span class="small muted">新增规则会调用 New-NetFirewallRule（Windows）或 ufw（Linux），失败原因会保存在列表中。</span>
  </div>
</div>

<dialog class="modal" id="fwCreate">
  <form method="post" action="<?= url('/firewall/create') ?>">
    <?= csrf_field() ?>
    <div class="modal__head">
      <h3>新增防火墙规则</h3>
      <button class="icon-btn" type="button" data-close="fwCreate"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <div class="form-grid">
        <div class="field span-2">
          <label>规则名称</label>
          <input class="input" type="text" name="name" placeholder="放行面板端口" required>
        </div>
        <div class="field">
          <label>方向</label>
          <select class="input" name="direction">
            <option value="inbound">入站</option>
            <option value="outbound">出站</option>
          </select>
        </div>
        <div class="field">
          <label>动作</label>
          <select class="input" name="action">
            <option value="allow">允许</option>
            <option value="block">阻止</option>
          </select>
        </div>
        <div class="field">
          <label>协议</label>
          <select class="input" name="protocol">
            <option value="tcp">TCP</option>
            <option value="udp">UDP</option>
          </select>
        </div>
        <div class="field">
          <label>端口</label>
          <input class="input" type="text" name="port" placeholder="8888 或 80,443 或 8000-9000">
        </div>
        <div class="field span-2">
          <label>来源 IP</label>
          <input class="input" type="text" name="remote_ip" value="any" placeholder="any 或 192.168.1.0/24">
        </div>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="fwCreate">取消</button>
      <button class="btn btn--primary" type="submit">写入规则</button>
    </div>
  </form>
</dialog>
