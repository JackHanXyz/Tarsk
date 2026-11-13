<?php
declare(strict_types=1);
$installed = $ini_path !== null;
?>
<div class="section-head">
  <div>
    <h2>PHP 配置</h2>
    <p>在线调整 php.ini 参数与扩展，保存后自动校验并重启对应 PHP-CGI；每次改动都会留备份。</p>
  </div>
  <div class="toolbar">
    <span class="badge <?= $state['running'] ? 'badge--ok' : 'badge--muted' ?>"><i></i><?= $state['running'] ? '运行中 · 端口 ' . (int)$state['port'] : '未运行' ?></span>
    <?php if ($installed): ?>
      <form method="post" action="<?= url('/env/php/restart') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="key" value="<?= e($key) ?>">
        <button class="btn btn--ghost btn--sm" type="submit"><?= icon('refresh', 14) ?>重启 PHP</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="toolbar" style="margin-bottom:14px;flex-wrap:wrap">
  <?php foreach ($versions as $v): ?>
    <?php $isCurrent = $v['version'] === $version; ?>
    <a class="btn btn--sm <?= $isCurrent ? 'btn--primary' : 'btn--ghost' ?>" href="<?= url('/env/php?ver=' . urlencode($v['version'])) ?>">
      PHP <?= e($v['version']) ?>
      <?php if (!$v['installed']): ?>
        <span class="badge badge--muted">未安装</span>
      <?php elseif ($v['running']): ?>
        <span class="badge badge--ok"><i></i></span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>

<?php if (!$installed): ?>
  <div class="alert alert--warning">
    <?= icon('alert', 16) ?>
    <span>PHP <?= e($version) ?> 尚未安装，无法读取 php.ini。请先在<a href="<?= url('/store') ?>">软件商店</a>安装该版本。</span>
  </div>
<?php else: ?>

<?php if ($ini_errors): ?>
  <div class="alert alert--error">
    <?= icon('alert', 16) ?>
    <span>当前 php.ini 存在 <?= count($ini_errors) ?> 处语法问题（第 <?= (int)$ini_errors[0]['line'] ?> 行：<?= e($ini_errors[0]['message']) ?>），建议在下方原始编辑器中修正。</span>
  </div>
<?php endif; ?>

<section class="grid grid--sidebar">
  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('settings', 17) ?>核心参数</span>
      <span class="badge badge--muted mono"><?= e(basename((string)$ini_path)) ?></span>
    </div>
    <form method="post" action="<?= url('/env/php/save') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="key" value="<?= e($key) ?>">
      <div class="card__body">
        <?php foreach ($knob_groups as $group => $knobs): ?>
          <div class="section-head" style="margin:6px 0 10px">
            <div><h3 style="margin:0;font-family:var(--font-display);font-size:14px"><?= e($group) ?></h3></div>
          </div>
          <div class="form-grid">
            <?php foreach ($knobs as $knob): ?>
              <div class="field">
                <label class="mono small"><?= e($knob['key']) ?></label>
                <?php if ($knob['type'] === 'bool'): ?>
                  <select class="input" name="<?= e($knob['key']) ?>">
                    <option value="On" <?= strcasecmp($knob['value'], 'On') === 0 || strcasecmp($knob['value'], '1') === 0 ? 'selected' : '' ?>>On</option>
                    <option value="Off" <?= strcasecmp($knob['value'], 'Off') === 0 || $knob['value'] === '0' || $knob['value'] === '' ? 'selected' : '' ?>>Off</option>
                  </select>
                <?php elseif ($knob['type'] === 'enum' && $knob['options']): ?>
                  <select class="input mono" name="<?= e($knob['key']) ?>">
                    <?php foreach ($knob['options'] as $opt): ?>
                      <option value="<?= e($opt) ?>" <?= $knob['value'] === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <input class="input mono" type="text" name="<?= e($knob['key']) ?>" value="<?= e($knob['value']) ?>" placeholder="<?= e($knob['label']) ?>">
                <?php endif; ?>
                <span class="hint"><?= e($knob['label']) ?><?= $knob['hint'] !== '' ? ' · ' . e($knob['hint']) : '' ?><?= !$knob['set'] ? '（当前未显式设置）' : '' ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        <label class="checkbox mt-12">
          <input type="checkbox" name="restart" value="1" checked>
          保存后自动重启 PHP-CGI（改扩展、内存、上传上限必须重启才生效）
        </label>
        <p class="hint mt-12">上传上限同时受 Nginx <code class="mono">client_max_body_size</code> 限制，站点详情页可单独调整。</p>
      </div>
      <div class="card__foot" style="display:flex;justify-content:flex-end">
        <button class="btn btn--primary" type="submit"><?= icon('save', 14) ?>保存并生效</button>
      </div>
    </form>
  </article>

  <div class="stack">
    <article class="card">
      <div class="card__head">
        <span class="card__title"><?= icon('box', 17) ?>运行信息</span>
      </div>
      <div class="card__body">
        <dl class="kv">
          <dt>版本</dt><dd class="mono">PHP <?= e($version) ?></dd>
          <dt>可执行文件</dt><dd class="mono small"><?= e($state['path']) ?>/php-cgi.exe</dd>
          <dt>配置文件</dt><dd class="mono small"><?= e((string)$ini_path) ?></dd>
          <dt>FastCGI 端口</dt><dd class="mono"><?= (int)$state['port'] ?></dd>
          <dt>进程号</dt><dd class="mono"><?= $state['pid'] ? (int)$state['pid'] : '—' ?></dd>
          <dt>错误日志</dt><dd class="mono small"><?= e($state['log_path']) ?></dd>
        </dl>
      </div>
    </article>

    <article class="card mt-18">
      <div class="card__head"><span class="card__title"><?= icon('save', 17) ?>配置备份</span></div>
      <div class="card__body card__body--flush">
        <?php if (!$backups): ?>
          <div class="empty">暂无备份</div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table table--compact">
              <thead><tr><th>时间</th><th>大小</th></tr></thead>
              <tbody>
              <?php foreach (array_slice($backups, 0, 8) as $b): ?>
                <tr>
                  <td class="small mono"><?= e($b['time']) ?></td>
                  <td class="small"><?= bytes($b['size']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
      <div class="card__foot"><span class="hint">备份文件位于 storage/backups/env，保留最近 20 份。</span></div>
    </article>
  </div>
</section>

<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('box', 17) ?>PHP 扩展</span>
    <span class="badge badge--info"><?= count(array_filter($extensions, fn($e) => $e['enabled'])) ?> 个已启用</span>
  </div>
  <form method="post" action="<?= url('/env/php/extensions') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="key" value="<?= e($key) ?>">
    <div class="card__body">
      <?php if (!$extensions): ?>
        <div class="empty">未扫描到扩展文件（ext 目录为空）</div>
      <?php else: ?>
        <div class="form-grid" style="gap:10px">
          <?php foreach ($extensions as $ext): ?>
            <label class="checkbox" <?= $ext['missing'] ? 'style="opacity:.55"' : '' ?>>
              <input type="checkbox" name="extensions[]" value="<?= e($ext['name']) ?>" <?= $ext['enabled'] ? 'checked' : '' ?> <?= $ext['missing'] ? 'disabled' : '' ?>>
              <span class="mono"><?= e($ext['name']) ?></span>
              <?php if ($ext['zend']): ?><span class="badge badge--info">zend</span><?php endif; ?>
              <?php if ($ext['recommended']): ?><span class="badge badge--muted">常用</span><?php endif; ?>
              <?php if ($ext['missing']): ?><span class="badge badge--warn">文件缺失</span><?php endif; ?>
              <?php if ($ext['size'] > 0): ?><span class="hint"><?= bytes($ext['size']) ?></span><?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
        <p class="hint mt-12">勾选即启用、取消即禁用。保存会重写 php.ini 中的 extension / zend_extension 行并重启 PHP；被勾选但文件缺失的条目会从配置中清除，避免启动告警。</p>
      <?php endif; ?>
    </div>
    <div class="card__foot" style="display:flex;justify-content:flex-end">
      <button class="btn btn--primary" type="submit"><?= icon('save', 14) ?>保存扩展</button>
    </div>
  </form>
</section>

<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('edit', 17) ?>原始 php.ini</span>
    <span class="badge badge--warn">谨慎操作</span>
  </div>
  <form method="post" action="<?= url('/env/php/raw') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="key" value="<?= e($key) ?>">
    <div class="card__body">
      <div class="field">
        <textarea class="input mono" name="content" rows="18" spellcheck="false"><?= e($ini_text) ?></textarea>
        <span class="hint">保存前会做语法检查并调用 php-cgi -v 实际验证，失败则拒绝写入，原文件不受影响。</span>
      </div>
      <label class="checkbox mt-12">
        <input type="checkbox" name="restart" value="1" checked>
        保存后自动重启 PHP-CGI
      </label>
    </div>
    <div class="card__foot" style="display:flex;justify-content:flex-end">
      <button class="btn btn--primary" type="submit"><?= icon('save', 14) ?>保存原始配置</button>
    </div>
  </form>
</section>

<?php endif; ?>
