<?php
declare(strict_types=1);
?>
<div class="section-head">
  <div>
    <div class="toolbar" style="gap:8px;margin-bottom:6px">
      <a class="badge badge--muted" href="<?= url('/sites') ?>"><?= icon('arrow-left', 12) ?>站点列表</a>
      <span class="badge <?= $site['status'] === 'running' ? 'badge--ok' : 'badge--muted' ?>"><i></i><?= $site['status'] === 'running' ? '运行中' : '已停止' ?></span>
      <?php if ($site['ssl_enabled']): ?><span class="badge badge--info">HTTPS</span><?php endif; ?>
    </div>
    <h2><?= e($site['name']) ?></h2>
    <p><?= e($site['domain'] ?: '未绑定域名') ?> · 端口 <?= (int)$effective_port ?><?= $nginx_managed ? ' · Tarsk 托管 Nginx' : '' ?></p>
  </div>
  <div class="toolbar">
    <a class="btn btn--ghost btn--sm" href="<?= url('/files?path=' . urlencode($site['name'])) ?>"><?= icon('folder', 14) ?>文件</a>
    <form method="post" action="<?= url('/sites/' . (int)$site['id'] . '/toggle') ?>">
      <?= csrf_field() ?>
      <button class="btn btn--ghost btn--sm" type="submit"><?= icon($site['status'] === 'running' ? 'stop' : 'play', 14) ?><?= $site['status'] === 'running' ? '停止站点' : '启动站点' ?></button>
    </form>
    <button class="btn btn--danger-ghost btn--sm" type="button" data-open="siteDeleteDetail"><?= icon('trash', 14) ?>删除</button>
  </div>
</div>

<section class="grid grid--sidebar">
  <article class="card">
    <div class="card__head"><span class="card__title"><?= icon('settings', 17) ?>站点配置</span></div>
    <form method="post" action="<?= url('/sites/' . (int)$site['id'] . '/update') ?>">
      <?= csrf_field() ?>
      <div class="card__body">
        <div class="form-grid">
          <div class="field">
            <label>绑定域名</label>
            <input class="input" type="text" name="domain" value="<?= e($site['domain']) ?>" placeholder="example.com">
          </div>
          <div class="field">
            <label>站点状态</label>
            <select class="input" name="status">
              <option value="running" <?= $site['status'] === 'running' ? 'selected' : '' ?>>运行中</option>
              <option value="stopped" <?= $site['status'] === 'stopped' ? 'selected' : '' ?>>已停止</option>
            </select>
          </div>
          <div class="field">
            <label>PHP 版本</label>
            <select class="input" name="php_version">
              <option value="">默认</option>
              <?php foreach ($php_options as $ver => $opt): ?>
                <option value="<?= e($ver) ?>" <?= $site['php_version'] === $ver ? 'selected' : '' ?> <?= !$opt['installed'] ? 'disabled' : '' ?>>
                  <?= e($opt['label']) ?><?= $opt['installed'] ? ($opt['running'] ? '（运行中）' : '（已安装，保存时自动启动）') : '（未安装）' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>伪静态规则</label>
            <select class="input" name="rewrite">
              <?php foreach ($rewrite_presets as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= ($site['rewrite'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field span-2">
            <label>备注</label>
            <input class="input" type="text" name="remark" value="<?= e($site['remark']) ?>" placeholder="可选">
          </div>
        </div>
        <p class="hint mt-12">保存后会立即重写 Nginx 配置；托管模式下会自动 reload，无需手动操作。</p>
      </div>
      <div class="card__foot" style="display:flex;justify-content:flex-end">
        <button class="btn btn--primary" type="submit"><?= icon('save', 14) ?>保存配置</button>
      </div>
    </form>
  </article>

  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('globe', 17) ?>运行信息</span>
      <a class="badge badge--info" href="<?= url('/ssl') ?>">SSL 证书</a>
    </div>
    <div class="card__body">
      <dl class="kv">
        <dt>运行目录</dt><dd class="mono"><?= e($site['path']) ?></dd>
        <dt>目录大小</dt><dd><?= is_dir((string)$site['path']) ? bytes(dir_size((string)$site['path'])) : '目录缺失' ?></dd>
        <dt>访问端口</dt><dd class="mono"><?= (int)$effective_port ?></dd>
        <dt>Nginx 配置</dt><dd class="mono small"><?= e($conf_path) ?></dd>
        <dt>访问日志</dt><dd class="mono small"><?= e($log_path ?: '未启用') ?></dd>
        <dt>创建时间</dt><dd><?= e(date('Y-m-d H:i', strtotime($site['created_at']))) ?></dd>
        <dt>HTTPS</dt><dd><?= $site['ssl_enabled'] ? '已启用' : '未启用' ?></dd>
      </dl>
    </div>
  </article>
</section>

<?php if ($databases): ?>
<section class="card mt-18">
  <div class="card__head"><span class="card__title"><?= icon('database', 17) ?>关联数据库</span></div>
  <div class="card__body card__body--flush">
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>数据库</th><th>备注</th><th>创建时间</th></tr></thead>
        <tbody>
        <?php foreach ($databases as $db): ?>
          <tr>
            <td class="cell-main mono"><?= e($db['db_name']) ?></td>
            <td class="small"><?= e($db['remark'] ?: '—') ?></td>
            <td class="small muted"><?= e($db['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="grid grid--2 mt-18">
  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('terminal', 17) ?>配置预览</span>
      <span class="badge badge--muted"><?= e($site['name']) ?>.conf</span>
    </div>
    <div class="card__body">
      <pre class="pre"><?= e($vhost ?: '配置文件尚未生成') ?></pre>
    </div>
  </article>

  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('list', 17) ?>访问日志（最近 30 行）</span>
      <a class="badge badge--muted" href="<?= url('/sites/' . (int)$site['id']) ?>">刷新</a>
    </div>
    <div class="card__body">
      <pre class="pre"><?= e($log_tail ?: '暂无日志。站点产生访问后会写入 ' . ($log_path ?: 'logs/' . $site['name'] . '.log')) ?></pre>
    </div>
  </article>
</section>

<?php if ($certs): ?>
<section class="card mt-18">
  <div class="card__head"><span class="card__title"><?= icon('lock', 17) ?>当前证书</span></div>
  <div class="card__body card__body--flush">
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>域名</th><th>签发者</th><th>到期</th><th>来源</th></tr></thead>
        <tbody>
        <?php foreach ($certs as $cert): ?>
          <tr>
            <td class="cell-main mono"><?= e($cert['domain']) ?></td>
            <td class="small"><?= e($cert['issuer'] ?: '—') ?></td>
            <td class="small"><?= e($cert['not_after'] ? date('Y-m-d', strtotime($cert['not_after'])) : '—') ?></td>
            <td><span class="badge badge--muted"><?= $cert['source'] === 'self' ? '自签名' : '导入' ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php endif; ?>

<dialog class="modal" id="siteDeleteDetail">
  <form method="post" action="<?= url('/sites/' . (int)$site['id'] . '/delete') ?>">
    <?= csrf_field() ?>
    <div class="modal__head">
      <h3>删除站点</h3>
      <button class="icon-btn" type="button" data-close="siteDeleteDetail"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <p>确定删除 <strong><?= e($site['name']) ?></strong>？站点记录与 Nginx 配置将被移除。</p>
      <label class="checkbox" style="margin-top:14px">
        <input type="checkbox" name="delete_files" value="1">
        同时删除站点目录及全部文件（不可恢复）
      </label>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="siteDeleteDetail">取消</button>
      <button class="btn btn--danger" type="submit">确认删除</button>
    </div>
  </form>
</dialog>
