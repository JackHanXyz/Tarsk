<?php
declare(strict_types=1);
?>
<div class="section-head">
  <div>
    <h2>计划任务</h2>
    <p>任务会同步注册到 <?= e($scheduler) ?>，可在系统层面独立运行；备份/切割/续期类任务由 Tarsk 任务执行器完成。</p>
  </div>
  <div class="toolbar">
    <button class="btn btn--primary btn--sm" data-open="cronCreate" <?= $command_exists ? '' : 'disabled' ?>><?= icon('plus', 14) ?>新建任务</button>
  </div>
</div>

<?php if (!$command_exists): ?>
  <div class="alert alert--warning">
    <?= icon('alert', 16) ?>
    <span>未检测到 <?= e($scheduler) ?> 命令，任务记录仍会保存，但无法注册到系统。请确认系统环境。</span>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card__head">
    <span class="card__title"><?= icon('clock', 17) ?>任务列表<span class="card__desc">共 <?= count($tasks) ?> 项</span></span>
    <span class="badge badge--info"><?= e($scheduler) ?></span>
  </div>
  <div class="card__body card__body--flush">
    <?php if (!$tasks): ?>
      <div class="empty">
        <?= icon('clock', 30) ?>
        <p>还没有计划任务</p>
        <small>可配置每日站点备份、数据库备份、日志切割与证书自动续期</small>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>任务</th>
              <th>类型</th>
              <th>执行计划</th>
              <th>目标</th>
              <th>系统状态</th>
              <th>上次执行</th>
              <th>状态</th>
              <th style="text-align:right">操作</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($tasks as $task): ?>
            <tr>
              <td>
                <div class="cell-main"><?= e($task['name']) ?></div>
                <div class="cell-sub">ID <?= (int)$task['id'] ?> · Tarsk_Task_<?= (int)$task['id'] ?></div>
              </td>
              <td><span class="badge badge--info"><?= e($task['type_label']) ?></span></td>
              <td><span class="badge badge--muted"><?= e($task['schedule_text']) ?></span></td>
              <td class="small mono" style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($task['command']) ?>"><?= e($task['target_text'] ?: '—') ?></td>
              <td>
                <span class="badge <?= $task['system_state'] === '未注册' ? 'badge--warn' : 'badge--ok' ?>"><?= e($task['system_state']) ?></span>
              </td>
              <td>
                <div class="small"><?= e($task['last_run_at'] ? ago($task['last_run_at']) : '从未') ?></div>
                <?php if ($task['last_result'] !== ''): ?>
                  <div class="cell-sub" title="<?= e($task['last_result']) ?>"><?= e(mb_substr($task['last_result'], 0, 22)) ?></div>
                <?php endif; ?>
              </td>
              <td><span class="badge <?= $task['status'] === 'enabled' ? 'badge--ok' : 'badge--muted' ?>"><i></i><?= $task['status'] === 'enabled' ? '启用' : '停用' ?></span></td>
              <td>
                <div class="td-actions">
                  <form method="post" action="<?= url('/cron/' . (int)$task['id'] . '/run') ?>">
                    <?= csrf_field() ?>
                    <button class="icon-btn" type="submit" title="立即执行"><?= icon('play', 13) ?></button>
                  </form>
                  <form method="post" action="<?= url('/cron/' . (int)$task['id'] . '/toggle') ?>">
                    <?= csrf_field() ?>
                    <button class="icon-btn" type="submit" title="<?= $task['status'] === 'enabled' ? '停用' : '启用' ?>"><?= icon($task['status'] === 'enabled' ? 'stop' : 'play', 13) ?></button>
                  </form>
                  <form method="post" action="<?= url('/cron/' . (int)$task['id'] . '/delete') ?>" data-confirm="确定删除任务「<?= e($task['name']) ?>」？">
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
    <span class="small muted">Windows 使用 schtasks 注册；修改系统任务可能需要管理员权限。</span>
  </div>
</div>

<dialog class="modal" id="cronCreate">
  <form method="post" action="<?= url('/cron/create') ?>">
    <?= csrf_field() ?>
    <div class="modal__head">
      <h3>新建计划任务</h3>
      <button class="icon-btn" type="button" data-close="cronCreate"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal__body">
      <div class="form-grid">
        <div class="field">
          <label>任务名称</label>
          <input class="input" type="text" name="name" placeholder="每日站点备份" required>
        </div>
        <div class="field">
          <label>任务类型</label>
          <select class="input" name="type" id="cronType">
            <?php foreach ($types as $value => $label): ?>
              <option value="<?= e($value) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>执行频率</label>
          <select class="input" name="schedule">
            <option value="daily">每天</option>
            <option value="every_minute">每分钟</option>
            <option value="every_5_minutes">每 5 分钟</option>
            <option value="hourly">每小时</option>
            <option value="weekly">每周日</option>
            <option value="boot">开机时</option>
          </select>
        </div>
        <div class="field">
          <label>执行时间</label>
          <input class="input" type="time" name="run_time" value="03:00">
        </div>

        <div class="field span-2 cron-field" data-for="command">
          <label>执行命令</label>
          <textarea class="input" name="command" rows="4" placeholder="例如：D:\BtSoft\php\php83\php.exe D:\wwwroot\backup.php"></textarea>
          <span class="hint">命令会由系统调度器直接执行，请使用绝对路径。</span>
        </div>

        <div class="field span-2 cron-field" data-for="site_backup">
          <label>备份站点</label>
          <select class="input" name="site_id">
            <option value="0">请选择站点</option>
            <?php foreach ($sites as $site): ?>
              <option value="<?= (int)$site['id'] ?>"><?= e($site['name']) ?><?= $site['domain'] !== '' ? '（' . e($site['domain']) . '）' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">打包站点目录全部文件为 zip，按保留策略自动清理旧备份。</span>
        </div>

        <div class="field span-2 cron-field" data-for="db_backup">
          <label>备份数据库</label>
          <select class="input" name="db_name">
            <option value="">请选择数据库</option>
            <?php foreach ($databases as $db): ?>
              <option value="<?= e($db['name']) ?>"><?= e($db['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">使用 mysqldump 导出表结构与数据到备份目录。</span>
        </div>

        <div class="field span-2 cron-field" data-for="log_cut">
          <label>切割日志范围</label>
          <select class="input" name="site_id">
            <option value="0">全部站点</option>
            <?php foreach ($sites as $site): ?>
              <option value="<?= (int)$site['id'] ?>"><?= e($site['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">把当前日志归档到 logs/archive/，并让 Nginx 重新打开日志文件。</span>
        </div>

        <div class="field span-2 cron-field" data-for="cert_renew">
          <label>说明</label>
          <span class="hint" style="line-height:2">检查所有 Let's Encrypt 证书，剩余有效期低于设置阈值（默认 30 天）时自动重新签发并回写站点配置。建议每天执行一次。</span>
        </div>

        <div class="field span-2">
          <label>提示</label>
          <span class="hint" style="line-height:2">每分钟/每小时/开机时任务会忽略执行时间字段。</span>
        </div>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="cronCreate">取消</button>
      <button class="btn btn--primary" type="submit">创建任务</button>
    </div>
  </form>
</dialog>

<script>
(function () {
  var typeSelect = document.getElementById('cronType');
  if (!typeSelect) { return; }
  var fields = document.querySelectorAll('.cron-field');
  function sync() {
    var type = typeSelect.value;
    fields.forEach(function (field) {
      var active = field.getAttribute('data-for') === type;
      field.style.display = active ? '' : 'none';
      field.querySelectorAll('input, select, textarea').forEach(function (el) {
        el.disabled = !active;
      });
    });
  }
  typeSelect.addEventListener('change', sync);
  sync();
})();
</script>
