<?php
declare(strict_types=1);
?>
<div class="section-head">
  <div>
    <h2>插件市场</h2>
    <p>插件位于 app/plugins 目录，安装启用后自动注册路由与导航。</p>
  </div>
</div>

<div class="app-grid mt-18">
  <?php foreach ($plugins as $plugin): ?>
    <article class="card app-card">
      <div class="card__head">
        <span class="card__title"><?= icon((string)($plugin['icon'] ?? 'box'), 17) ?><?= e((string)($plugin['name'] ?? $plugin['key'])) ?></span>
        <?php if (!$plugin['installed']): ?>
          <span class="badge badge--muted">未安装</span>
        <?php elseif ($plugin['enabled']): ?>
          <span class="badge badge--ok"><i></i>已启用</span>
        <?php else: ?>
          <span class="badge badge--warn">已停用</span>
        <?php endif; ?>
      </div>
      <div class="card__body">
        <p class="hint" style="margin:0 0 12px"><?= e((string)($plugin['description'] ?? '')) ?></p>
        <p class="small muted mono" style="margin:0">v<?= e((string)($plugin['version'] ?? '1.0')) ?> · <?= e($plugin['key']) ?></p>
      </div>
      <div class="card__foot" style="display:flex;gap:8px;justify-content:flex-end">
        <?php if (!$plugin['installed']): ?>
          <form method="post" action="<?= url('/plugins/install') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="key" value="<?= e($plugin['key']) ?>">
            <button class="btn btn--primary btn--sm" type="submit"><?= icon('plus', 14) ?>安装并启用</button>
          </form>
        <?php else: ?>
          <form method="post" action="<?= url('/plugins/toggle') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="key" value="<?= e($plugin['key']) ?>">
            <button class="btn btn--ghost btn--sm" type="submit"><?= icon('refresh', 14) ?><?= $plugin['enabled'] ? '停用' : '启用' ?></button>
          </form>
          <form method="post" action="<?= url('/plugins/uninstall') ?>" data-confirm="确定卸载该插件？插件文件会保留。">
            <?= csrf_field() ?>
            <input type="hidden" name="key" value="<?= e($plugin['key']) ?>">
            <button class="btn btn--danger-ghost btn--sm" type="submit"><?= icon('trash', 14) ?>卸载</button>
          </form>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>

<?php if (!$plugins): ?>
  <div class="card mt-18">
    <div class="empty">
      <?= icon('box', 30) ?>
      <p>暂无插件</p>
      <small>把插件目录放入 app/plugins 后刷新本页</small>
    </div>
  </div>
<?php endif; ?>

<div class="card mt-18">
  <div class="card__head"><span class="card__title"><?= icon('edit', 17) ?>开发自己的插件</span></div>
  <div class="card__body">
    <p class="hint">插件目录结构：</p>
    <pre class="terminal" style="padding:12px"><code>app/plugins/&lt;key&gt;/
├── plugin.json   {"key":"demo","name":"示例","version":"1.0","description":"...","icon":"box","nav":{"title":"示例","href":"/plugins/demo","icon":"box"}}
├── routes.php    &lt;?php return [['GET','/plugins/demo','main.php','demo_page']];
└── main.php      &lt;?php function demo_page(){ render('plugin_page', ['title'=>'示例','active'=>'plugins','content_html'=>'&lt;p&gt;你好&lt;/p&gt;'], 'app'); }</code></pre>
    <p class="small muted">插件可以直接使用面板全部全局函数（数据库、渲染、设置等）。</p>
  </div>
</div>
