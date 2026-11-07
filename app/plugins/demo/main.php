<?php
declare(strict_types=1);

function demo_plugin_page(): void
{
    require_login();
    $html = '<h3 style="margin-top:0">示例插件已生效</h3>'
        . '<p class="hint">这个页面由 app/plugins/demo/main.php 渲染，通过 plugin.json 注册导航、routes.php 注册路由。</p>'
        . '<p class="hint">当前面板时间：' . e(now()) . '；已安装站点数：'
        . count(fetch_all('SELECT id FROM ' . t('sites'))) . '。</p>';
    render('plugin_page', [
        'title' => '插件示例',
        'active' => 'plugin:/plugins/demo',
        'content_html' => $html,
    ], 'app');
}
