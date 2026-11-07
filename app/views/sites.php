<?php
declare(strict_types=1);
?>
<div class="section-head">
  <div>
    <h2>站点管理</h2>
    <p>创建站点会同时生成运行目录、默认首页与 Nginx 配置文件。</p>
  </div>
  <div class="toolbar">
    <a class="btn btn--ghost btn--sm" href="<?= url('/files') ?>"><?= icon('folder', 14) ?>打开文件管理</a>
    <button class="btn btn--primary btn--sm" data-open="siteCreate"><?= icon('plus', 14) ?>新建站点</button>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <span class="card__title"><?= icon('globe', 17) ?>运行环境</span>
    <div class="toolbar">
      <?php if ($nginx_managed): ?>
        <span class="badge <?= $nginx_running ? 'badge--ok' : 'badge--warn' ?>"><i></i>Tarsk 托管 Nginx · 端口 <?= (int)$web_port ?></span>
      <?php else: ?>
        <span class="badge <?= $nginx_bin ? 'badge--ok' : 'badge--warn' ?>"><i></i><?= $nginx_bin ? '系统 Nginx' : '未检测到 Nginx' ?></span>
        <a class="btn btn--soft btn--sm" href="<?= url('/store') ?>"><?= icon('download', 13) ?>安装托管环境</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="card__body">
    <dl class="kv">
      <dt>站点根目录</dt><dd class="mono"><?= e($sites_root) ?></dd>
      <dt>Nginx 配置</dt><dd class="mono"><?= e($nginx_conf) ?></dd>
      <dt>Nginx 程序</dt><dd class="mono"><?= e($nginx_bin ?: '未检测到，配置文件仍会生成') ?></dd>
      <dt>访问端口</dt><dd class="mono"><?= (int)$web_port ?> <?= $nginx_managed ? '（托管模式）' : '' ?></dd>
      <dt>PHP 环境</dt>
      <dd>
        <?php foreach ($php_options as $ver => $opt): ?>
          <?php if ($opt['installed']): ?>
            <span class="badge <?= $opt['running'] ? 'badge--ok' : 'badge--warn' ?>" style="margin-right:4px">
              <?= e($opt['label']) ?> · <?= (int)$opt['port'] ?> · <?= $opt['running'] ? '运行中' : '未启动' ?>
            </span>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php foreach ($php_versions as $ver): ?>
          <span class="badge badge--muted" style="margin-right:4px"><?= e($ver['version']) ?></span>
        <?php endforeach; ?>
      </dd>
    </dl>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <span class="card__title"><?= icon('globe', 17) ?>站点列表<span class="card__desc">共 <?= count($sites) ?> 个</span></span>
    <div class="search-box">
      <?= icon('search', 14) ?>
      <input type="search" placeholder="搜索站点或域名" data-table-search="sitesTable">
    </div>
  </div>
  <div class="card__body card__body--flush">
    <?php if (!$sites): ?>
      <div class="empty">
        <?= icon('globe', 30) ?>
        <p>还没有站点</p>
        <small>点击右上角「新建站点」开始，目录会自动创建</small>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table" id="sitesTable">
          <thead>
            <tr>
              <th>站点</th>
              <th>域名</th>
              <th>运行目录</th>
              <th>PHP</th>
              <th>SSL</th>
              <th>状态</th>
              <th style="text-align:right">操作</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($sites as $site): ?>
            <tr data-search="<?= e(strtolower($site['name'] . ' ' . $site['domain'])) ?>">
              <td>
                <div class="cell-main"><a href="<?= url('/sites/' . (int)$site['id']) ?>"><?= e($site['name']) ?></a></div>
                <div class="cell-sub"><?= e(ago($site['created_at'])) ?>创建 · <?= bytes($site['size']) ?></div>
              </td>
              <td>
                <?php if ($site['domain'] !== ''): ?>
                  <span class="mono"><?= e($site['domain']) ?></span>
                <?php else: ?>
                  <span class="muted">未绑定</span>
                <?php endif; ?>
              </td>
              <td class="mono small"><?= e($site['path']) ?></td>
              <td><?= $site['php_version'] !== '' ? e($site['php_version']) : '<span class="muted">默认</span>' ?></td>
              <td>
                <span class="badge <?= $site['ssl_enabled'] ? 'badge--ok' : 'badge--muted' ?>">
                  <?= $site['ssl_enabled'] ? '已启用' : '未启用' ?>
                </span>
              </td>
              <td>
                <span class="badge <?= $site['status'] === 'running' ? 'badge--ok' : 'badge--muted' ?>">
                  <i></i><?= $site['status'] === 'running' ? '运行中' : '已停止' ?>
                </span>
                <?php if (!$site['exists']): ?>
                  <div class="cell-sub">目录缺失</div>
                <?php endif; ?>
              </td>
              <td>
                <div class="td-actions">
                  <a class="icon-btn" href="<?= url('/sites/' . (int)$site['id']) ?>" title="站点设置"><?= icon('settings', 14) ?></a>
                  <a class="icon-btn" href="<?= url('/files?path=' . urlencode($site['name'])) ?>" title="浏览文件"><?= icon('folder', 14) ?></a>
                  <form method="post" action="<?= url('/sites/' . (int)$site['id'] . '/toggle') ?>">
                    <?= csrf_field() ?>
                    <button class="icon-btn" type="submit" title="<?= $site['status'] === 'running' ? '停止' : '启动' ?>">
                      <?= icon($site['status'] === 'running' ? 'stop' : 'play', 14) ?>
                    </button>
                  </form>
                  <button class="icon-btn icon-btn--danger" type="button"
                    data-open="siteDelete"
                    data-site-id="<?= (int)$site['id'] ?>"
                    data-site-name="<?= e($site['name']) ?>"
                    title="删除"><?= icon('trash', 14) ?></button>
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
    <span class="small muted">停止站点会把对应 Nginx 配置文件移除，重新启动时自动重建。</span>
  </div>
</div>

<dialog class="modal" id="siteCreate">
  <form method="post" action="<?= url('/sites/create') ?>">
    <?= csrf_field() ?>
    <div class="modal__head">
      <h3>新建站点</h3>
      <button class="icon-btn" type="button" data-close="siteCreate"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <div class="form-grid">
        <div class="field">
          <label>站点名称</label>
          <input class="input" type="text" name="name" placeholder="my-site" required pattern="[A-Za-z0-9][A-Za-z0-9._-]*">
          <span class="hint">用于创建目录与配置文件名</span>
        </div>
        <div class="field">
          <label>绑定域名</label>
          <input class="input" type="text" name="domain" placeholder="example.com（可留空）">
        </div>
        <div class="field">
          <label>PHP 版本</label>
          <select class="input" name="php_version">
            <option value="">默认</option>
            <?php foreach ($php_options as $ver => $opt): ?>
              <option value="<?= e($ver) ?>" <?= !$opt['installed'] ? 'disabled' : '' ?>>
                <?= e($opt['label']) ?><?= $opt['installed'] ? ($opt['running'] ? '（运行中 :' . (int)$opt['port'] . '）' : '（已安装）') : '（未安装）' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>伪静态</label>
          <select class="input" name="rewrite">
            <?php foreach ($rewrite_presets as $value => $label): ?>
              <option value="<?= e($value) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">可在站点设置中随时切换</span>
        </div>
        <div class="field span-2">
          <label>备注</label>
          <input class="input" type="text" name="remark" placeholder="可选，例如：博客主站">
        </div>
        <div class="field span-2">
          <label class="checkbox">
            <input type="checkbox" name="create_db" value="1">
            同时创建 MySQL 数据库
          </label>
        </div>
        <div class="field span-2">
          <label>数据库名称</label>
          <input class="input mono" type="text" name="db_name" placeholder="留空则使用站点名称，非法字符会自动替换为下划线">
        </div>
      </div>
      <p class="small muted mt-12">站点将监听当前 Web 端口 <?= (int)$web_port ?><?= $nginx_managed ? '（Tarsk 托管 Nginx）' : '' ?>。</p>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="siteCreate">取消</button>
      <button class="btn btn--primary" type="submit">创建站点</button>
    </div>
  </form>
</dialog>

<dialog class="modal" id="siteDelete">
  <form method="post" id="siteDeleteForm">
    <?= csrf_field() ?>
    <div class="modal__head">
      <h3>删除站点</h3>
      <button class="icon-btn" type="button" data-close="siteDelete"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <p>确定要删除站点 <strong id="siteDeleteName">—</strong> 吗？站点记录与 Nginx 配置文件将被移除。</p>
      <label class="checkbox" style="margin-top:14px">
        <input type="checkbox" name="delete_files" value="1" id="siteDeleteDeleteFiles">
        同时删除站点目录及全部文件（不可恢复）
      </label>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="siteDelete">取消</button>
      <button class="btn btn--danger" type="submit">确认删除</button>
    </div>
  </form>
</dialog>
