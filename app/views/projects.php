<?php
declare(strict_types=1);

/**
 * 应用托管列表页。
 * @var array $projects
 * @var array $hooks
 * @var array $binaries
 * @var bool  $git
 */
$statusBadge = static fn(string $s) => match ($s) {
    'running' => '<span class="badge badge--ok">运行中</span>',
    'failed' => '<span class="badge badge--danger">异常退出</span>',
    default => '<span class="badge badge--muted">已停止</span>',
};
$kindLabel = ['node' => 'Node.js', 'python' => 'Python', 'java' => 'Java'];
?>
<div class="section-head">
  <div>
    <h2>应用托管</h2>
    <p>以分离进程方式托管 Node.js / Python / Java 项目：启动、停止、探活、日志与开机自启。</p>
  </div>
  <div class="toolbar">
    <button class="btn btn--primary btn--sm" data-open="projCreate"><?= icon('plus', 14) ?>添加应用</button>
  </div>
</div>

<?php if (!$binaries['node'] && !$binaries['python'] && !$binaries['java']): ?>
  <div class="alert alert--warning">
    <?= icon('alert', 16) ?>
    <span>未检测到 node / python / java 可执行文件。可到<a href="<?= url('/store') ?>">软件商店</a>安装，或自行安装后刷新本页。</span>
  </div>
<?php else: ?>
  <div class="card mt-18">
    <div class="card__body">
      <span class="small muted">运行时检测：</span>
      <?php foreach ($binaries as $kind => $bin): ?>
        <span class="badge badge--<?= $bin ? 'info' : 'muted' ?>"><?= e($kindLabel[$kind]) ?><?= $bin ? ' · ' . e($bin) : ' 未安装' ?></span>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="card mt-18">
  <div class="card__head"><span class="card__title"><?= icon('box', 17) ?>应用列表</span></div>
  <div class="card__body card__body--flush">
    <?php if (!$projects): ?>
      <div class="empty">还没有托管应用。点击「添加应用」把 node server.js、python main.py、java -jar app.jar 这类项目交给面板管理。</div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr><th>名称</th><th>类型</th><th>启动命令</th><th>端口</th><th>PID</th><th>状态</th><th>自启</th><th style="text-align:right">操作</th></tr>
          </thead>
          <tbody>
          <?php foreach ($projects as $p): ?>
            <tr>
              <td class="cell-main">
                <strong><?= e($p['name']) ?></strong>
                <div class="small muted mono"><?= e($p['path']) ?></div>
              </td>
              <td><?= e($kindLabel[$p['kind']] ?? $p['kind']) ?></td>
              <td class="mono small"><?= e($p['command']) ?></td>
              <td><?= (int)$p['port'] > 0 ? (int)$p['port'] : '—' ?></td>
              <td class="mono"><?= (int)$p['pid'] > 0 ? (int)$p['pid'] : '—' ?></td>
              <td><?= $statusBadge((string)$p['status']) ?></td>
              <td><?= !empty($p['autostart']) ? '<span class="badge badge--info">开机自启</span>' : '<span class="badge badge--muted">否</span>' ?></td>
              <td>
                <div class="td-actions">
                  <?php if (($p['alive'] ?? false)): ?>
                    <form method="post" action="<?= url('/projects/' . (int)$p['id'] . '/stop') ?>">
                      <?= csrf_field() ?>
                      <button class="btn btn--danger-ghost btn--sm" type="submit"><?= icon('pause', 14) ?>停止</button>
                    </form>
                  <?php else: ?>
                    <form method="post" action="<?= url('/projects/' . (int)$p['id'] . '/start') ?>">
                      <?= csrf_field() ?>
                      <button class="btn btn--primary btn--sm" type="submit"><?= icon('play', 14) ?>启动</button>
                    </form>
                  <?php endif; ?>
                  <a class="btn btn--ghost btn--sm" href="<?= url('/projects/' . (int)$p['id'] . '/logs') ?>"><?= icon('file', 14) ?>日志</a>
                  <form method="post" action="<?= url('/projects/' . (int)$p['id'] . '/delete') ?>" data-confirm="将停止进程、移除自启任务并删除该项目记录，确认？">
                    <?= csrf_field() ?>
                    <button class="btn btn--danger-ghost btn--sm" type="submit"><?= icon('trash', 14) ?></button>
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
  <?php if ($projects): ?>
    <div class="card__foot"><span class="small muted">状态为实时探活结果；异常退出请点「日志」查看 stderr。</span></div>
  <?php endif; ?>
</div>

<section class="card mt-18" id="git">
  <div class="card__head">
    <span class="card__title"><?= icon('globe', 17) ?>Git 部署</span>
    <span class="badge badge--<?= $git ? 'ok' : 'danger' ?>"><?= $git ? 'git 可用' : '未安装 git' ?></span>
  </div>
  <div class="card__body">
    <form method="post" action="<?= url('/projects/webhook') ?>" class="grid grid-2">
      <?= csrf_field() ?>
      <div class="field">
        <label>站点</label>
        <select class="input" name="site_id">
          <?php foreach (fetch_all('SELECT id, name FROM ' . t('sites') . ' ORDER BY name') as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>仓库地址</label>
        <input class="input" name="repo" placeholder="https://github.com/user/repo.git">
      </div>
      <div class="field">
        <label>分支</label>
        <input class="input" name="branch" value="main">
      </div>
      <div class="field">
        <label>自定义 secret（留空自动生成/保持不变）</label>
        <input class="input" name="secret" placeholder="32 位十六进制，留空自动处理">
      </div>
      <div class="field span-2">
        <button class="btn btn--primary" type="submit"><?= icon('save', 14) ?>保存绑定</button>
        <span class="hint">首次拉取要求站点目录为空（执行 clone）；已有仓库则 fetch + reset 到远端分支（未提交的本地改动会被覆盖）。</span>
      </div>
    </form>

    <?php if ($hooks): ?>
      <div class="table-wrap mt-12">
        <table class="table">
          <thead>
            <tr><th>站点</th><th>仓库</th><th>分支</th><th>Webhook 地址</th><th>最近拉取</th><th style="text-align:right">操作</th></tr>
          </thead>
          <tbody>
          <?php foreach ($hooks as $h): ?>
            <?php
            $hookRow = ['secret' => $h['secret']];
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $hookUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:' . ($_SERVER['SERVER_PORT'] ?? '80')) . '/webhook/' . $h['secret'];
            ?>
            <tr>
              <td class="cell-main"><strong><?= e($h['site_name']) ?></strong></td>
              <td class="mono small"><?= e($h['repo']) ?></td>
              <td><?= e($h['branch']) ?></td>
              <td class="mono small"><?= e($hookUrl) ?></td>
              <td class="small">
                <?= $h['last_pull_at'] ? e($h['last_pull_at']) : '从未' ?>
                <?php if ($h['last_result'] !== ''): ?><div class="muted"><?= e($h['last_result']) ?></div><?php endif; ?>
              </td>
              <td>
                <div class="td-actions">
                  <form method="post" action="<?= url('/projects/webhook/' . (int)$h['id'] . '/pull') ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn--ghost btn--sm" type="submit"><?= icon('refresh', 14) ?>立即拉取</button>
                  </form>
                  <form method="post" action="<?= url('/projects/webhook/' . (int)$h['id'] . '/delete') ?>" data-confirm="解绑后 webhook 地址立即失效，确认？">
                    <?= csrf_field() ?>
                    <button class="btn btn--danger-ghost btn--sm" type="submit"><?= icon('trash', 14) ?></button>
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
</section>

<section class="card mt-18" id="projCreate">
  <div class="card__head"><span class="card__title"><?= icon('plus', 17) ?>添加 / 编辑应用</span></div>
  <div class="card__body">
    <form method="post" action="<?= url('/projects/save') ?>" class="grid grid-2">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="0">
      <div class="field">
        <label>项目名</label>
        <input class="input" name="name" required placeholder="my-api">
      </div>
      <div class="field">
        <label>类型</label>
        <select class="input" name="kind">
          <option value="node">Node.js</option>
          <option value="python">Python</option>
          <option value="java">Java</option>
        </select>
      </div>
      <div class="field span-2">
        <label>项目目录（绝对路径）</label>
        <input class="input" name="path" required placeholder="D:/apps/my-api">
      </div>
      <div class="field span-2">
        <label>启动命令（相对项目目录执行）</label>
        <input class="input" name="command" required placeholder="node server.js 或 python main.py 或 java -jar app.jar">
      </div>
      <div class="field">
        <label>监听端口（可选，用于展示）</label>
        <input class="input" type="number" name="port" min="0" max="65535" value="0">
      </div>
      <div class="field">
        <label class="check"><input type="checkbox" name="autostart" value="1"> 开机自启</label>
      </div>
      <div class="field span-2">
        <button class="btn btn--primary" type="submit"><?= icon('save', 14) ?>保存</button>
      </div>
    </form>
  </div>
</section>
