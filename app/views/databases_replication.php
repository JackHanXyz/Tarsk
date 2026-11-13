<?php
declare(strict_types=1);
$dbTabs = [
    ['key' => 'list', 'label' => '数据库', 'url' => '/databases', 'icon' => 'database'],
    ['key' => 'users', 'label' => '用户与权限', 'url' => '/databases/users', 'icon' => 'user'],
    ['key' => 'slow', 'label' => '慢查询报表', 'url' => '/databases/slow', 'icon' => 'activity'],
    ['key' => 'replication', 'label' => '主从与同步', 'url' => '/databases/replication', 'icon' => 'network'],
];
$master = $status['master'] ?? [];
$slave = $status['slave'] ?? [];
$binlog = $status['binlog'] ?? [];
$isSlave = !empty($slave);
$binlogOn = strtolower((string)($binlog['log_bin'] ?? 'OFF')) !== 'off';
?>
<div class="section-head">
  <div>
    <h2>主从与同步</h2>
    <p>查看二进制日志与复制状态；面板不做自动改配置，只给出可复制的命令与配置片段。</p>
  </div>
  <div class="toolbar">
    <?php if ($isSlave): ?>
      <span class="badge badge--info">当前角色：从库</span>
    <?php elseif (!empty($master)): ?>
      <span class="badge badge--ok">当前角色：主库（binlog 已开启）</span>
    <?php else: ?>
      <span class="badge badge--muted">未启用复制</span>
    <?php endif; ?>
    <a class="btn btn--ghost btn--sm" href="<?= url('/databases/replication') ?>"><?= icon('refresh', 14) ?>刷新</a>
  </div>
</div>

<div class="toolbar" style="margin-bottom:14px;flex-wrap:wrap">
  <?php foreach ($dbTabs as $tab): ?>
    <a class="btn btn--sm <?= $tab['key'] === 'replication' ? 'btn--primary' : 'btn--ghost' ?>" href="<?= url($tab['url']) ?>"><?= icon($tab['icon'], 14) ?><?= e($tab['label']) ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$status['ok']): ?>
  <div class="alert alert--error">
    <?= icon('alert', 16) ?>
    <span>无法读取复制状态：<?= e($status['message']) ?></span>
  </div>
<?php else: ?>

<section class="stats" style="grid-template-columns:repeat(4,minmax(0,1fr))">
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon"><?= icon('server', 16) ?></span>server_id</span></div>
    <div class="stat__value" style="font-size:20px"><?= e($status['server_id'] !== '' ? $status['server_id'] : '—') ?></div>
    <div class="stat__meta"><span>MySQL</span><span><?= e($version ?: '—') ?></span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--ok"><?= icon('activity', 16) ?></span>binlog</span><span class="badge <?= $binlogOn ? 'badge--ok' : 'badge--muted' ?>"><?= $binlogOn ? 'ON' : 'OFF' ?></span></div>
    <div class="stat__value" style="font-size:17px"><?= e($binlogOn ? ($binlog['binlog_format'] ?? '—') : '未开启') ?></div>
    <div class="stat__meta"><span>sync_binlog</span><span><?= e($binlog['sync_binlog'] ?? '—') ?></span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--violet"><?= icon('network', 16) ?></span>复制角色</span></div>
    <div class="stat__value" style="font-size:20px"><?= $isSlave ? '从库' : (!empty($master) ? '主库' : '单机') ?></div>
    <div class="stat__meta"><span>GTID</span><span><?= e($status['gtid'] !== '' ? $status['gtid'] : 'OFF') ?></span></div>
  </article>
  <article class="stat">
    <div class="stat__head"><span class="stat__label"><span class="stat__icon stat__icon--warn"><?= icon('clock', 16) ?></span>延迟</span></div>
    <div class="stat__value" style="font-size:20px"><?= $isSlave ? (int)($slave['Seconds_Behind_Master'] ?? 0) . 's' : '—' ?></div>
    <div class="stat__meta"><span>IO / SQL 线程</span><span><?= $isSlave ? e(($slave['Slave_IO_Running'] ?? '?') . ' / ' . ($slave['Slave_SQL_Running'] ?? '?')) : '—' ?></span></div>
  </article>
</section>

<?php if ($isSlave): ?>
<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('network', 17) ?>从库状态（SHOW SLAVE STATUS）</span>
    <span class="badge <?= ($slave['Slave_IO_Running'] ?? '') === 'Yes' && ($slave['Slave_SQL_Running'] ?? '') === 'Yes' ? 'badge--ok' : 'badge--danger' ?>">
      <?= ($slave['Slave_IO_Running'] ?? '') === 'Yes' && ($slave['Slave_SQL_Running'] ?? '') === 'Yes' ? '同步正常' : '同步异常' ?>
    </span>
  </div>
  <div class="card__body">
    <dl class="kv">
      <dt>主库地址</dt><dd class="mono"><?= e((string)($slave['Master_Host'] ?? '')) ?>:<?= (int)($slave['Master_Port'] ?? 0) ?></dd>
      <dt>复制账号</dt><dd class="mono"><?= e((string)($slave['Master_User'] ?? '')) ?></dd>
      <dt>当前日志</dt><dd class="mono small"><?= e((string)($slave['Master_Log_File'] ?? '')) ?> · pos <?= (int)($slave['Read_Master_Log_Pos'] ?? 0) ?></dd>
      <dt>执行位置</dt><dd class="mono small"><?= e((string)($slave['Relay_Master_Log_File'] ?? '')) ?> · exec <?= (int)($slave['Exec_Master_Log_Pos'] ?? 0) ?></dd>
      <dt>延迟</dt><dd><?= (int)($slave['Seconds_Behind_Master'] ?? 0) ?> 秒</dd>
      <dt>上次错误</dt><dd class="small"><?= e((string)($slave['Last_Error'] ?? '') ?: '无') ?></dd>
    </dl>
  </div>
</section>
<?php elseif (!empty($master)): ?>
<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('network', 17) ?>主库状态（SHOW MASTER STATUS）</span>
    <span class="badge badge--ok">可用于搭建从库</span>
  </div>
  <div class="card__body">
    <dl class="kv">
      <dt>当前日志</dt><dd class="mono"><?= e((string)($master['File'] ?? '')) ?></dd>
      <dt>位置</dt><dd class="mono"><?= (int)($master['Position'] ?? 0) ?></dd>
      <dt>binlog_do_db</dt><dd class="mono small"><?= e((string)($master['Binlog_Do_DB'] ?? '') ?: '（全部）') ?></dd>
      <dt>binlog_ignore_db</dt><dd class="mono small"><?= e((string)($master['Binlog_Ignore_DB'] ?? '') ?: '（无）') ?></dd>
    </dl>
  </div>
</section>
<?php endif; ?>

<section class="grid grid--2 mt-18">
  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('settings', 17) ?>my.cnf 配置片段</span>
      <span class="badge badge--muted">需重启 mysqld</span>
    </div>
    <div class="card__body">
      <p class="hint">主库（<span class="mono"><?= e($host) ?>:<?= e($port) ?></span>）：</p>
      <pre class="pre pre--light mt-12">[mysqld]
server-id = 1
log-bin = mysql-bin
binlog-format = ROW
expire_logs_days = 7
max_binlog_size = 256M

# 从库 server-id 需唯一，例如 2；两端的 server-id 不能相同</pre>
      <p class="hint mt-12">从库额外建议（只读 + 崩溃安全）：</p>
      <pre class="pre pre--light mt-12">read_only = ON
relay_log = relay-bin
log_slave_updates = ON
sync_binlog = 1</pre>
    </div>
  </article>

  <article class="card">
    <div class="card__head">
      <span class="card__title"><?= icon('terminal', 17) ?>从库初始化命令</span>
      <span class="badge badge--muted">在从库执行</span>
    </div>
    <div class="card__body">
      <pre class="pre pre--light">-- 1. 主库上创建复制账号
CREATE USER 'repl'@'%' IDENTIFIED BY '强密码';
GRANT REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO 'repl'@'%';
FLUSH PRIVILEGES;

-- 2. 主库导出一致性快照（另开终端）
mysqldump -u root -p --single-transaction --master-data=2 --all-databases > all.sql

-- 3. 从库导入后指向主库（pos 取 SHOW MASTER STATUS 的值）
CHANGE MASTER TO
  MASTER_HOST='<?= e($host) ?>',
  MASTER_PORT=<?= (int)$port ?>,
  MASTER_USER='repl',
  MASTER_PASSWORD='强密码',
  MASTER_LOG_FILE='<?= e((string)($master['File'] ?? 'mysql-bin.000001')) ?>',
  MASTER_LOG_POS=<?= (int)($master['Position'] ?? 0) ?>;

START SLAVE;
SHOW SLAVE STATUS\G</pre>
      <p class="hint mt-12">面板只做状态展示与命令生成，不自动改 my.cnf 或执行 CHANGE MASTER —— 复制拓扑属于高风险操作，建议在维护窗口手工完成。</p>
    </div>
  </article>
</section>

<?php if (!$binlogOn): ?>
<div class="alert alert--warning mt-18">
  <?= icon('alert', 16) ?>
  <span>当前实例未开启 <code class="mono">log_bin</code>，无法作为主库使用。开启需要在 my.cnf 中加入 <code class="mono">server-id</code> 与 <code class="mono">log-bin</code> 后重启 MySQL。</span>
</div>
<?php endif; ?>

<?php endif; ?>
