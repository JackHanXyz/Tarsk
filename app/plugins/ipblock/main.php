<?php
declare(strict_types=1);

function ipblock_page(): void
{
    require_login();
    $list = (string)setting('ipblock_list', '');
    $html = '<form method="post" action="' . url('/plugins/ipblock/save') . '">'
        . csrf_field()
        . '<div class="field"><label>黑名单列表（每行一个 IP，支持 1.2.3.* 通配）</label>'
        . '<textarea class="input mono" name="list" rows="8" placeholder="1.2.3.4&#10;5.6.7.*">' . e($list) . '</textarea></div>'
        . '<button class="btn btn--primary btn--sm" type="submit">' . icon('save', 14) . '保存</button>'
        . '</form>'
        . '<p class="hint" style="margin-top:12px">当前访问 IP：<span class="mono">' . e(client_ip()) . '</span>（请避免误封自己）</p>';
    render('plugin_page', [
        'title' => 'IP 黑名单',
        'active' => 'plugin:/plugins/ipblock',
        'content_html' => $html,
    ], 'app');
}

function ipblock_save(): void
{
    require_login();
    $list = trim((string)($_POST['list'] ?? ''));
    setting_set('ipblock_list', $list);
    log_action('IP 黑名单', '更新规则 ' . ($list === '' ? 0 : count(explode("\n", $list))) . ' 条', 'warning');
    flash('success', '黑名单已保存，立即生效');
    redirect('/plugins/ipblock');
}
