<?php
declare(strict_types=1);

/**
 * Tarsk 路由表
 * [方法, 路径正则, 控制器文件, 处理函数]
 */
return [
    ['GET', '/', 'dashboard.php', 'dashboard_page'],
    ['GET', '/login', 'auth.php', 'login_page'],
    ['POST', '/login', 'auth.php', 'login_submit'],
    ['POST', '/logout', 'auth.php', 'logout'],

    ['GET', '/sites', 'sites.php', 'sites_page'],
    ['POST', '/sites/create', 'sites.php', 'sites_create'],
    ['POST', '/sites/(\d+)/toggle', 'sites.php', 'sites_toggle'],
    ['POST', '/sites/(\d+)/delete', 'sites.php', 'sites_delete'],

    ['GET', '/files', 'files.php', 'files_page'],
    ['GET', '/files/read', 'files.php', 'files_read'],
    ['POST', '/files/mkdir', 'files.php', 'files_mkdir'],
    ['POST', '/files/save', 'files.php', 'files_save'],
    ['POST', '/files/rename', 'files.php', 'files_rename'],
    ['POST', '/files/delete', 'files.php', 'files_delete'],
    ['POST', '/files/upload', 'files.php', 'files_upload'],
    ['GET', '/files/download', 'files.php', 'files_download'],

    ['GET', '/databases', 'databases.php', 'databases_page'],
    ['POST', '/databases/create', 'databases.php', 'databases_create'],
    ['POST', '/databases/drop', 'databases.php', 'databases_drop'],
    ['POST', '/databases/backup', 'databases.php', 'databases_backup'],
    ['GET', '/databases/tables', 'databases.php', 'databases_tables'],

    ['GET', '/monitor', 'monitor.php', 'monitor_page'],
    ['GET', '/api/metrics', 'monitor.php', 'metrics_api'],

    ['GET', '/cron', 'cron.php', 'cron_page'],
    ['POST', '/cron/create', 'cron.php', 'cron_create'],
    ['POST', '/cron/(\d+)/run', 'cron.php', 'cron_run'],
    ['POST', '/cron/(\d+)/toggle', 'cron.php', 'cron_toggle'],
    ['POST', '/cron/(\d+)/delete', 'cron.php', 'cron_delete'],

    ['GET', '/firewall', 'firewall.php', 'firewall_page'],
    ['POST', '/firewall/create', 'firewall.php', 'firewall_create'],
    ['POST', '/firewall/(\d+)/delete', 'firewall.php', 'firewall_delete'],

    ['GET', '/docker', 'docker.php', 'docker_page'],
    ['POST', '/docker/action', 'docker.php', 'docker_action'],
    ['POST', '/docker/run', 'docker.php', 'docker_run'],

    ['GET', '/ssl', 'ssl.php', 'ssl_page'],
    ['POST', '/ssl/generate', 'ssl.php', 'ssl_generate'],
    ['POST', '/ssl/upload', 'ssl.php', 'ssl_upload'],
    ['POST', '/ssl/(\d+)/delete', 'ssl.php', 'ssl_delete'],

    ['GET', '/store', 'store.php', 'store_page'],
    ['POST', '/store/install', 'store.php', 'store_install'],
    ['POST', '/store/check', 'store.php', 'store_check'],

    ['GET', '/logs', 'logs.php', 'logs_page'],

    ['GET', '/settings', 'settings.php', 'settings_page'],
    ['POST', '/settings/save', 'settings.php', 'settings_save'],
    ['POST', '/settings/password', 'settings.php', 'settings_password'],
];
