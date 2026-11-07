<?php
declare(strict_types=1);
?>
<div class="section-head">
  <div>
    <h2>Docker 容器</h2>
    <p>调用本机 Docker CLI 管理容器与镜像。</p>
  </div>
  <div class="toolbar">
    <?php if ($info['installed'] && $info['running']): ?>
      <button class="btn btn--primary btn--sm" data-open="dockerRun"><?= icon('plus', 14) ?>运行容器</button>
    <?php endif; ?>
    <a class="btn btn--ghost btn--sm" href="<?= url('/docker') ?>"><?= icon('refresh', 14) ?>刷新</a>
  </div>
</div>

<?php if (!$info['installed']): ?>
  <div class="card">
    <div class="empty" style="padding:54px 20px">
      <?= icon('box', 34) ?>
      <p>本机尚未安装 Docker</p>
      <small style="display:block;max-width:520px;margin:6px auto 18px;line-height:1.8">
        前往软件商店一键安装 Docker Desktop，或在命令行执行 <code>winget install Docker.DockerDesktop</code>。
        安装完成后需启动 Docker Desktop 才能管理容器。
      </small>
      <a class="btn btn--primary" href="<?= url('/store') ?>">前往软件商店</a>
      <a class="btn btn--ghost" href="https://www.docker.com/products/docker-desktop/" target="_blank" rel="noopener"><?= icon('external', 14) ?>官网下载</a>
    </div>
  </div>
<?php else: ?>
  <?php if (!$info['running']): ?>
    <div class="alert alert--warning">
      <?= icon('alert', 16) ?>
      <span>Docker CLI 版本 <?= e($info['version']) ?>，但守护进程未就绪。请启动 Docker Desktop 后刷新。
        <?php if ($info['error'] !== ''): ?><br><span class="mono small"><?= e(mb_substr($info['error'], 0, 200)) ?></span><?php endif; ?>
      </span>
    </div>
  <?php endif; ?>

  <section class="stats">
    <article class="stat">
      <div class="stat__head"><span class="stat__label"><span class="stat__icon"><?= icon('box', 16) ?></span>Docker CLI</span></div>
      <div class="stat__value" style="font-size:20px"><?= e($info['version'] ?: '未知版本') ?></div>
      <div class="stat__meta"><span>守护进程</span><span><?= $info['running'] ? '已连接' : '未连接' ?></span></div>
    </article>
    <article class="stat">
      <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--ok"><?= icon('server', 16) ?></span>容器</span></div>
      <div class="stat__value"><?= count($info['containers']) ?><small>个</small></div>
      <div class="stat__meta"><span>运行中 <?= count(array_filter($info['containers'], fn($c) => strtolower($c['state']) === 'running')) ?></span></div>
    </article>
    <article class="stat">
      <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--violet"><?= icon('box', 16) ?></span>镜像</span></div>
      <div class="stat__value"><?= count($info['images']) ?><small>个</small></div>
      <div class="stat__meta"><span>本地镜像缓存</span></div>
    </article>
    <article class="stat">
      <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--warn"><?= icon('terminal', 16) ?></span>面板端口</span></div>
      <div class="stat__value" style="font-size:18px">8888</div>
      <div class="stat__meta"><span>示例映射</span></div>
    </article>
  </section>

  <div class="card mt-18">
    <div class="card__head">
      <span class="card__title"><?= icon('box', 17) ?>容器列表</span>
      <span class="badge badge--muted"><?= count($info['containers']) ?></span>
    </div>
    <div class="card__body card__body--flush">
      <?php if (!$info['containers']): ?>
        <div class="empty">
          <?= icon('box', 30) ?>
          <p>暂无容器</p>
          <small>点击「运行容器」通过镜像创建一个实例</small>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>容器</th><th>镜像</th><th>状态</th><th>ID</th><th style="text-align:right">操作</th></tr></thead>
            <tbody>
            <?php foreach ($info['containers'] as $container): ?>
              <?php $running = strtolower($container['state']) === 'running'; ?>
              <tr>
                <td>
                  <div class="cell-main"><?= e($container['name']) ?></div>
                  <div class="cell-sub mono"><?= e(substr($container['id'], 0, 12)) ?></div>
                </td>
                <td class="mono small"><?= e($container['image']) ?></td>
                <td><span class="badge <?= $running ? 'badge--ok' : 'badge--muted' ?>"><i></i><?= e($container['status']) ?></span></td>
                <td class="mono small"><?= e($container['id']) ?></td>
                <td>
                  <div class="td-actions">
                    <form method="post" action="<?= url('/docker/action') ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= e($container['id']) ?>">
                      <input type="hidden" name="action" value="<?= $running ? 'stop' : 'start' ?>">
                      <button class="icon-btn" type="submit" title="<?= $running ? '停止' : '启动' ?>"><?= icon($running ? 'stop' : 'play', 13) ?></button>
                    </form>
                    <form method="post" action="<?= url('/docker/action') ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= e($container['id']) ?>">
                      <input type="hidden" name="action" value="restart">
                      <button class="icon-btn" type="submit" title="重启"><?= icon('refresh', 13) ?></button>
                    </form>
                    <form method="post" action="<?= url('/docker/action') ?>" data-confirm="确定强制删除容器 <?= e($container['name']) ?>？">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= e($container['id']) ?>">
                      <input type="hidden" name="action" value="rm">
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

  <div class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('box', 17) ?>本地镜像</span>
      <span class="badge badge--muted"><?= count($info['images']) ?></span>
    </div>
    <div class="card__body card__body--flush">
      <?php if (!$info['images']): ?>
        <div class="empty" style="padding:24px"><?= icon('box', 26) ?><p>暂无本地镜像</p></div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>仓库</th><th>标签</th><th>大小</th><th>镜像 ID</th></tr></thead>
            <tbody>
            <?php foreach ($info['images'] as $image): ?>
              <tr>
                <td class="cell-main mono"><?= e($image['repo']) ?></td>
                <td><span class="badge badge--info"><?= e($image['tag']) ?></span></td>
                <td class="small"><?= e($image['size']) ?></td>
                <td class="small muted mono"><?= e(substr($image['id'], 0, 19)) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<dialog class="modal" id="dockerRun">
  <form method="post" action="<?= url('/docker/run') ?>">
    <?= csrf_field() ?>
    <div class="modal__head">
      <h3>运行容器</h3>
      <button class="icon-btn" type="button" data-close="dockerRun"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <div class="form-grid">
        <div class="field span-2">
          <label>镜像名称</label>
          <input class="input" type="text" name="image" placeholder="nginx:latest" required>
          <span class="hint">本地不存在时会自动拉取，耗时取决于网络。</span>
        </div>
        <div class="field">
          <label>容器名称</label>
          <input class="input" type="text" name="name" placeholder="web-01">
        </div>
        <div class="field">
          <label>端口映射</label>
          <input class="input" type="text" name="ports" placeholder="8080:80">
        </div>
        <div class="field span-2">
          <label>环境变量</label>
          <textarea class="input" name="env" rows="3" placeholder="每行一个，例如：&#10;TZ=Asia/Shanghai&#10;MYSQL_ROOT_PASSWORD=secret"></textarea>
        </div>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="dockerRun">取消</button>
      <button class="btn btn--primary" type="submit"><?= icon('play', 14) ?>启动容器</button>
    </div>
  </form>
</dialog>
