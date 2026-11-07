<?php
declare(strict_types=1);
?>
<div class="section-head">
  <div>
    <h2>计划任务</h2>
    <p>任务会同步注册到 <?= e($scheduler) ?>，可在系统层面独立运行。</p>
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
        <small>可用于定时备份、日志清理、数据同步等场景</small>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>任务</th>
              <th>执行计划</th>
              <th>命令</th>
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
              <td><span class="badge badge--info"><?= e($task['schedule_text']) ?></span></td>
              <td class="mono small" style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($task['command']) ?>"><?= e($task['command']) ?></td>
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
          <input class="input" type="text" name="name" placeholder="每日数据库备份" required>
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
        <div class="field">
          <label>提示</label>
          <span class="hint" style="line-height:2.4">每分钟/每小时/开机时任务会忽略时间字段</span>
        </div>
        <div class="field span-2">
          <label>执行命令</label>
          <textarea class="input" name="command" rows="4" placeholder="例如：D:\BtSoft\php\php83\php.exe D:\wwwroot\backup.php" required></textarea>
          <span class="hint">命令会由系统调度器直接执行，请使用绝对路径。</span>
        </div>
      </div>
    </div>
    <div class="modal__foot">
      <button class="btn btn--ghost" type="button" data-close="cronCreate">取消</button>
      <button class="btn btn--primary" type="submit">创建任务</button>
    </div>
  </form>
</dialog>
