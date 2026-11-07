<?php
declare(strict_types=1);
?>
<div class="section-head">
  <div>
    <h2>Web 终端</h2>
    <p>在面板内直接执行系统命令，输出实时返回。命令以 <?= e($platform) ?> 当前运行账号身份执行。</p>
  </div>
  <div class="toolbar">
    <span class="chip"><i></i><?= e($platform) ?> · <?= e($user) ?></span>
  </div>
</div>

<article class="card">
  <div class="card__head">
    <span class="card__title"><?= icon('terminal', 17) ?>交互式命令</span>
    <span class="badge badge--muted mono">cwd: <?= e($cwd) ?></span>
  </div>
  <div class="card__body" style="padding-bottom:14px">
    <div class="terminal" id="terminalScreen">
      <div class="term-entry term-entry--hint">
        <div class="term-line term-cmd"><span class="term-prompt">Tarsk</span> Web Terminal ready · <?= e($platform) ?></div>
        <pre class="term-out">输入命令并回车执行。支持系统命令与应用 CLI，输出会保留在本次会话中。</pre>
      </div>
      <?php foreach ($history as $item): ?>
        <div class="term-entry">
          <div class="term-line term-cmd"><span class="term-prompt">$</span><?= e($item['command']) ?><span class="term-meta"><?= e($item['time']) ?> · <?= e((string)$item['duration']) ?>s · exit <?= (int)$item['code'] ?></span></div>
          <pre class="term-out"><?= e($item['output']) ?></pre>
        </div>
      <?php endforeach; ?>
    </div>

    <form id="terminalForm" class="term-input-row" autocomplete="off">
      <?= csrf_field() ?>
      <span class="term-prompt">$</span>
      <input id="terminalCommand" name="command" type="text" placeholder="输入命令，例如 php -v、tasklist、ipconfig" spellcheck="false" autocomplete="off">
      <button class="btn btn--primary btn--sm" type="submit" id="terminalSubmit">执行</button>
    </form>
  </div>
  <div class="card__foot">
    <div class="toolbar">
      <span class="small muted">快捷命令：</span>
      <?php foreach (['php -v', 'tasklist', 'ipconfig', 'netstat -ano', 'php bin/install.php'] as $example): ?>
        <button class="btn btn--ghost btn--sm mono" type="button" data-term-fill="<?= e($example) ?>"><?= e($example) ?></button>
      <?php endforeach; ?>
    </div>
  </div>
</article>

<div class="alert alert--warning" style="margin-top:16px">
  <?= icon('alert', 16) ?>
  <span>终端会真实执行命令，且拥有与面板进程相同的系统权限。请避免在此执行高危操作（如格式化磁盘、批量删除系统目录）。所有命令会记录到操作日志。</span>
</div>
