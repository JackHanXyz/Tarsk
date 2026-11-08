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
    ['GET', '/login/2fa', 'auth.php', 'login_2fa_page'],
    ['POST', '/login/2fa', 'auth.php', 'login_2fa_submit'],
    ['POST', '/logout', 'auth.php', 'logout'],

    ['GET', '/sites', 'sites.php', 'sites_page'],
    ['GET', '/sites/(\d+)', 'sites.php', 'sites_show'],
    ['POST', '/sites/create', 'sites.php', 'sites_create'],
    ['POST', '/sites/(\d+)/update', 'sites.php', 'sites_update'],
    ['POST', '/sites/(\d+)/toggle', 'sites.php', 'sites_toggle'],
    ['POST', '/sites/(\d+)/delete', 'sites.php', 'sites_delete'],

    ['GET', '/files', 'files.php', 'files_page'],
    ['GET', '/files/read', 'files.php', 'files_read'],
    ['POST', '/files/mkdir', 'files.php', 'files_mkdir'],
    ['POST', '/files/create', 'files.php', 'files_create'],
    ['POST', '/files/save', 'files.php', 'files_save'],
    ['POST', '/files/rename', 'files.php', 'files_rename'],
    ['POST', '/files/delete', 'files.php', 'files_delete'],
    ['POST', '/files/upload', 'files.php', 'files_upload'],
    ['GET', '/files/download', 'files.php', 'files_download'],

    ['GET', '/recycle', 'recycle.php', 'recycle_page'],
    ['POST', '/recycle/restore', 'recycle.php', 'recycle_restore'],
    ['POST', '/recycle/delete', 'recycle.php', 'recycle_delete'],
    ['POST', '/recycle/clear', 'recycle.php', 'recycle_clear'],

    ['GET', '/deploy', 'deploy.php', 'deploy_page'],
    ['POST', '/deploy/run', 'deploy.php', 'deploy_run'],

    ['GET', '/databases', 'databases.php', 'databases_page'],
    ['POST', '/databases/create', 'databases.php', 'databases_create'],
    ['POST', '/databases/drop', 'databases.php', 'databases_drop'],
    ['POST', '/databases/backup', 'databases.php', 'databases_backup'],
    ['GET', '/databases/tables', 'databases.php', 'databases_tables'],

    ['GET', '/backups', 'backups.php', 'backups_page'],
    ['POST', '/backups/create', 'backups.php', 'backups_create'],
    ['POST', '/backups/delete', 'backups.php', 'backups_delete'],
    ['POST', '/backups/restore', 'backups.php', 'backups_restore'],
    ['GET', '/backups/download', 'backups.php', 'backups_download'],

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
    ['POST', '/ssl/letsencrypt', 'ssl.php', 'ssl_letsencrypt'],
    ['POST', '/ssl/renew', 'ssl.php', 'ssl_renew'],
    ['POST', '/ssl/(\d+)/delete', 'ssl.php', 'ssl_delete'],

    ['GET', '/store', 'store.php', 'store_page'],
    ['POST', '/store/install', 'store.php', 'store_install'],
    ['POST', '/store/check', 'store.php', 'store_check'],
    ['POST', '/store/install-managed', 'store.php', 'store_managed_install'],
    ['POST', '/store/managed-action', 'store.php', 'store_managed_action'],
    ['GET', '/store/log', 'store.php', 'store_log_api'],

    ['GET', '/terminal', 'terminal.php', 'terminal_page'],
    ['POST', '/terminal/run', 'terminal.php', 'terminal_run'],

    ['GET', '/processes', 'processes.php', 'processes_page'],
    ['POST', '/processes/kill', 'processes.php', 'processes_kill'],

    ['GET', '/ftp', 'ftp.php', 'ftp_page'],
    ['POST', '/ftp/user/create', 'ftp.php', 'ftp_user_create'],
    ['POST', '/ftp/user/delete', 'ftp.php', 'ftp_user_delete'],
    ['POST', '/ftp/user/toggle', 'ftp.php', 'ftp_user_toggle'],
    ['POST', '/ftp/service', 'ftp.php', 'ftp_service_action'],

    ['GET', '/plugins', 'plugins_market.php', 'plugins_page'],
    ['POST', '/plugins/install', 'plugins_market.php', 'plugins_install'],
    ['POST', '/plugins/toggle', 'plugins_market.php', 'plugins_toggle'],
    ['POST', '/plugins/uninstall', 'plugins_market.php', 'plugins_uninstall'],

    ['GET', '/logs', 'logs.php', 'logs_page'],

    ['GET', '/settings', 'settings.php', 'settings_page'],
    ['POST', '/settings/save', 'settings.php', 'settings_save'],
    ['POST', '/settings/password', 'settings.php', 'settings_password'],
    ['POST', '/settings/security', 'settings.php', 'settings_security_save'],
    ['POST', '/settings/2fa/prepare', 'settings.php', 'settings_2fa_prepare'],
    ['POST', '/settings/2fa/enable', 'settings.php', 'settings_2fa_enable'],
    ['POST', '/settings/2fa/disable', 'settings.php', 'settings_2fa_disable'],
    ['POST', '/settings/panel-ssl', 'settings.php', 'settings_panel_ssl'],
];
