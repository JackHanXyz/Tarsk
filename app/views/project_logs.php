<?php
declare(strict_types=1);

/**
 * 应用日志页。
 * @var array $project
 * @var string $stdout
 * @var string $stderr
 */
?>
<div class="section-head">
  <div>
    <h2>日志 · <?= e($project['name']) ?></h2>
    <p class="mono small"><?= e($project['path']) ?> · <?= e($project['command']) ?></p>
  </div>
  <div class="toolbar">
    <a class="btn btn--ghost btn--sm" href="<?= url('/projects') ?>"><?= icon('arrow-left', 14) ?>返回列表</a>
  </div>
</div>

<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('file', 17) ?>标准输出（最近 200 行）</span>
  </div>
  <div class="card__body">
    <pre class="mono small" style="max-height:420px;overflow:auto;white-space:pre-wrap"><?= e($stdout !== '' ? $stdout : '（暂无输出）') ?></pre>
  </div>
</section>

<section class="card mt-18">
  <div class="card__head">
    <span class="card__title"><?= icon('alert', 17) ?>错误输出（stderr，最近 200 行）</span>
  </div>
  <div class="card__body">
    <pre class="mono small" style="max-height:420px;overflow:auto;white-space:pre-wrap"><?= e($stderr !== '' ? $stderr : '（暂无输出）') ?></pre>
  </div>
</section>
