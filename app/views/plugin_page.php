<?php
declare(strict_types=1);
/**
 * 插件通用页面包装：插件生成 content_html 后用本视图套面板布局输出
 */
?>
<div class="section-head">
  <div>
    <h2><?= e($title) ?></h2>
  </div>
</div>
<div class="card">
  <div class="card__body">
    <?= $content_html ?? '' ?>
  </div>
</div>
