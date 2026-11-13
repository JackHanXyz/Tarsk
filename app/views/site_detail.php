<?php
declare(strict_types=1);
?>
<div class="section-head">
  <div>
    <div class="toolbar" style="gap:8px;margin-bottom:6px">
      <a class="badge badge--muted" href="<?= url('/sites') ?>"><?= icon('arrow-left', 12) ?>站点列表</a>
      <span class="badge <?= $site['status'] === 'running' ? 'badge--ok' : 'badge--muted' ?>"><i></i><?= $site['status'] === 'running' ? '运行中' : '已停止' ?></span>
      <?php if ($site['ssl_enabled']): ?><span class="badge badge--info">HTTPS</span><?php endif; ?>
      <?php if (!empty($site['http2_enabled'])): ?><span class="badge badge--info">HTTP/2</span><?php endif; ?>
      <?php if ((int)($site['rate_limit'] ?? 0) > 0): ?><span class="badge badge--warn">限流 <?= (int)$site['rate_limit'] ?>r/s</span><?php endif; ?>
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
            <label>主域名</label>
            <input class="input" type="text" name="domain" value="<?= e($site['domain']) ?>" placeholder="example.com">
          </div>
          <div class="field">
            <label>站点状态</label>
            <select class="input" name="status">
              <option value="running" <?= $site['status'] === 'running' ? 'selected' : '' ?>>运行中</option>
              <option value="stopped" <?= $site['status'] === 'stopped' ? 'selected' : '' ?>>已停止</option>
            </select>
          </div>
          <div class="field span-2">
            <label>附加域名</label>
            <textarea class="input mono" name="domains" rows="2" placeholder="www.example.com, example.net"><?= e($site['domains'] ?? '') ?></textarea>
            <span class="hint">每行一个或逗号分隔，全部写入 server_name（不包含主域名本身）。</span>
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
          <div class="field">
            <label>默认文档</label>
            <input class="input mono" type="text" name="index_files" value="<?= e($site['index_files'] ?? 'index.html index.htm index.php') ?>" placeholder="index.html index.php">
            <span class="hint">空格分隔，最多 6 个</span>
          </div>
          <div class="field">
            <label>备注</label>
            <input class="input" type="text" name="remark" value="<?= e($site['remark']) ?>" placeholder="可选">
          </div>
          <div class="field span-2">
            <label>高级选项</label>
            <label class="checkbox">
              <input type="checkbox" name="force_https" value="1" <?= !empty($site['force_https']) ? 'checked' : '' ?>>
              强制 HTTPS（HTTP 请求 301 跳转，需已启用证书）
            </label>
            <label class="checkbox">
              <input type="checkbox" name="anti_leech" value="1" <?= !empty($site['anti_leech']) ? 'checked' : '' ?>>
              防盗链（图片/视频/压缩包仅允许本站 Referer 访问）
            </label>
            <label class="checkbox">
              <input type="checkbox" name="open_basedir" value="1" <?= !empty($site['open_basedir']) ? 'checked' : '' ?>>
              防跨站 open_basedir（限制 PHP 只能访问站点目录与临时目录）
            </label>
          </div>
          <div class="field span-2">
            <label>上传与流量限制</label>
            <div class="form-grid" style="gap:12px">
              <div class="field">
                <label class="small">单请求上传上限</label>
                <input class="input mono" type="text" name="upload_size" value="<?= e($site['upload_size'] ?: '64m') ?>" placeholder="64m">
                <span class="hint">Nginx client_max_body_size，如 64m / 1g</span>
              </div>
              <div class="field">
                <label class="small">限流速率（请求/秒）</label>
                <input class="input mono" type="number" name="rate_limit" min="0" max="10000" value="<?= (int)($site['rate_limit'] ?? 0) ?>" placeholder="0">
                <span class="hint">0 = 不限流，防 CC 建议 5~20</span>
              </div>
              <div class="field">
                <label class="small">突发队列（burst）</label>
                <input class="input mono" type="number" name="rate_burst" min="0" max="100000" value="<?= (int)($site['rate_burst'] ?? 0) ?>" placeholder="默认 2 倍速率">
                <span class="hint">允许瞬时超出的请求数，超出返回 503</span>
              </div>
              <div class="field">
                <label class="small">单 IP 并发连接</label>
                <input class="input mono" type="number" name="conn_limit" min="0" max="10000" value="<?= (int)($site['conn_limit'] ?? 0) ?>" placeholder="0">
                <span class="hint">0 = 不限制，limit_conn 上限</span>
              </div>
            </div>
          </div>
          <div class="field span-2">
            <label>HTTPS 加固</label>
            <label class="checkbox">
              <input type="checkbox" name="http2_enabled" value="1" <?= !empty($site['http2_enabled']) ? 'checked' : '' ?>>
              HTTP/2（443 端口启用多路复用，需已启用证书）
            </label>
            <label class="checkbox">
              <input type="checkbox" name="hsts_enabled" value="1" <?= !empty($site['hsts_enabled']) ? 'checked' : '' ?>>
              HSTS（Strict-Transport-Security，max-age 1 年 + includeSubDomains）
            </label>
          </div>
          <div class="field span-2">
            <label>反向代理目标</label>
            <input class="input mono" type="text" name="proxy_pass" value="<?= e($site['proxy_pass'] ?? '') ?>" placeholder="http://127.0.0.1:3000">
            <span class="hint">支持 http:// 或 https:// 开头的主机:端口，可带路径前缀。启用后站点不再走 PHP。</span>
            <label class="checkbox mt-12">
              <input type="checkbox" name="proxy_enabled" value="1" <?= !empty($site['proxy_enabled']) ? 'checked' : '' ?>>
              启用反向代理
            </label>
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
        <dt>绑定域名</dt><dd class="mono small"><?= e(site_server_names($site)) ?></dd>
        <dt>Nginx 配置</dt><dd class="mono small"><?= e($conf_path) ?></dd>
        <dt>访问日志</dt><dd class="mono small"><?= e($log_path ?: '未启用') ?></dd>
        <dt>创建时间</dt><dd><?= e(date('Y-m-d H:i', strtotime($site['created_at']))) ?></dd>
        <dt>上传上限</dt><dd class="mono"><?= e(site_upload_limit($site)) ?></dd>
        <dt>流量限制</dt><dd><?php
          $limitParts = [];
          if ((int)($site['rate_limit'] ?? 0) > 0) {
              $limitParts[] = (int)$site['rate_limit'] . ' 请求/秒';
          }
          if ((int)($site['conn_limit'] ?? 0) > 0) {
              $limitParts[] = '单 IP 并发 ' . (int)$site['conn_limit'];
          }
          echo $limitParts ? e(implode(' · ', $limitParts)) : '未限流';
        ?></dd>
        <dt>HTTPS</dt><dd><?= $site['ssl_enabled'] ? '已启用' : '未启用' ?><?= !empty($site['force_https']) && $site['ssl_enabled'] ? ' · 强制跳转' : '' ?></dd>
      </dl>
    </div>
  </article>
</section>

<?php if (!empty($site['proxy_enabled'])): ?>
<div class="alert alert--info mt-18">
  <?= icon('zap', 16) ?>
  <span>反向代理已启用，当前请求转发至 <code class="mono"><?= e($site['proxy_pass']) ?></code>。若目标服务未运行，站点会返回 502。</span>
</div>
<?php endif; ?>

<section class="grid grid--2 mt-18">
  <article class="card" id="quick-proxy">
    <div class="card__head"><span class="card__title"><?= icon('zap', 17) ?>一键配置反向代理</span><span class="badge <?= !empty($site['proxy_enabled']) ? 'badge--ok' : 'badge--muted' ?>"><?= !empty($site['proxy_enabled']) ? '已启用' : '未启用' ?></span></div>
    <form method="post" action="<?= url('/sites/' . (int)$site['id'] . '/proxy') ?>" data-confirm="确认写入并测试 Nginx 配置，然后立即 reload？">
      <?= csrf_field() ?>
      <div class="card__body">
        <div class="field">
          <label>后端服务地址</label>
          <input class="input mono" type="url" name="proxy_pass" value="<?= e($site['proxy_pass'] ?? '') ?>" placeholder="http://127.0.0.1:3000" required>
          <span class="hint">支持 http:// 或 https://、主机名/IP、端口和路径前缀。请确认后端服务已启动。</span>
        </div>
      </div>
      <div class="card__foot" style="display:flex;justify-content:flex-end;gap:8px">
        <?php if (!empty($site['proxy_enabled'])): ?><button class="btn btn--ghost btn--sm" type="submit" name="action" value="disable" formnovalidate>关闭代理</button><?php endif; ?>
        <button class="btn btn--primary btn--sm" type="submit" name="action" value="enable"><?= icon('zap', 13) ?>配置并应用</button>
      </div>
    </form>
  </article>

  <article class="card" id="quick-ssl">
    <div class="card__head"><span class="card__title"><?= icon('lock', 17) ?>一键申请 SSL 证书</span><span class="badge <?= !empty($site['ssl_enabled']) ? 'badge--ok' : 'badge--muted' ?>"><?= !empty($site['ssl_enabled']) ? 'HTTPS 已启用' : '未启用' ?></span></div>
    <form method="post" action="<?= url('/ssl/letsencrypt') ?>" data-confirm="将向 Let's Encrypt 提交 <?= e((string)($site['domain'] ?? '')) ?> 的公开证书申请。请确认域名已解析到本服务器且 HTTP 80 端口可从公网访问。">
      <?= csrf_field() ?>
      <input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>">
      <input type="hidden" name="quick" value="1">
      <div class="card__body">
        <div class="field">
          <label>通知邮箱（可选）</label>
          <input class="input" type="email" name="email" placeholder="留空则使用 admin@域名">
          <span class="hint">Let's Encrypt 使用 HTTP-01 验证；申请成功后自动保存证书、测试配置并启用 HTTPS。</span>
        </div>
      </div>
      <div class="card__foot" style="display:flex;justify-content:space-between;align-items:center;gap:8px">
        <span class="hint">当前域名：<span class="mono"><?= e($site['domain'] ?: '未绑定') ?></span></span>
        <button class="btn btn--primary btn--sm" type="submit" <?= trim((string)$site['domain']) === '' ? 'disabled' : '' ?>>申请并启用</button>
      </div>
    </form>
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

<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('edit', 17) ?>站点自定义配置</span>
    <span class="badge badge--warn">写入后自动 nginx -t 校验</span>
  </div>
  <form method="post" action="<?= url('/sites/' . (int)$site['id'] . '/extra') ?>">
    <?= csrf_field() ?>
    <div class="card__body">
      <div class="field">
        <textarea class="input mono" name="extra" rows="8" spellcheck="false" placeholder="# 只写 server 块内的指令，例如：
add_header X-Frame-Options SAMEORIGIN always;
location /static/ {
    expires 7d;
}"><?= e($extra_conf) ?></textarea>
        <span class="hint">
          这段内容会被 include 进本站点的每个 server 块，可直接写 <code class="mono">location</code> / <code class="mono">add_header</code> / <code class="mono">rewrite</code> 等指令。
          保存失败会自动回滚，留空表示清空。文件：<span class="mono"><?= e($extra_path) ?></span>
        </span>
      </div>
    </div>
    <div class="card__foot" style="display:flex;justify-content:space-between;align-items:center">
      <span class="hint">重新保存站点常规配置不会覆盖这里的内容。</span>
      <button class="btn btn--primary btn--sm" type="submit"><?= icon('save', 14) ?>保存自定义配置</button>
    </div>
  </form>
</section>

<?php if ($certs): ?><section class="card mt-18">
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
            <td><span class="badge badge--muted"><?= $cert['source'] === 'self' ? '自签名' : ($cert['source'] === 'letsencrypt' ? "Let's Encrypt" : '导入') ?></span></td>
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
