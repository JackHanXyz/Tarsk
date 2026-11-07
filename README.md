# Tarsk · 轻量服务器运维面板

Tarsk 是一个以 PHP + MySQL 编写的服务器运维面板，复刻宝塔面板的核心使用方式：站点、文件、数据库、监控、计划任务、防火墙、Docker、SSL 与软件商店集中在一个后台中。

## 特性

- **真实系统操作**：文件读写、站点目录创建、Nginx vhost 生成、数据库创建/备份、`schtasks` 计划任务、`New-NetFirewallRule` 防火墙规则、Docker CLI 管理与 OpenSSL 证书签发。
- **真实指标采集**：Windows 通过 CIM/PowerShell 采集 CPU、内存、磁盘与网卡累计流量；Linux 读取 `/proc` 与 `disk_*_space`。
- **跨平台适配**：Windows 调试优先，Linux 提供等价实现（crontab、ufw、systemctl）。
- **零第三方依赖**：无 Composer，纯原生 PHP 模板与原生 CSS/JS，离线可用。
- **安全基线**：会话 Cookie HttpOnly + SameSite、全站 CSRF Token、路径穿越防护、操作日志。

## 环境要求

- PHP 8.1+（需要 `pdo_mysql`、`openssl`、`mbstring` 扩展）
- MySQL 5.6+（面板自身数据使用 `tarsk_` 表前缀）
- 可选：Nginx、MySQL 客户端、Docker、winget（用于软件商店安装）

## 快速开始

```bash
# 1. 准备配置
cp config.example.php config.php
# 编辑 config.php 填写数据库连接信息

# 2. 初始化数据表与管理员
php bin/install.php admin YourPassword123

# 3. 启动
php -S 127.0.0.1:8888 -t public public/router.php
```

访问 `http://127.0.0.1:8888`，使用初始化时创建的管理员账号登录。

也可以直接访问 `/install` 使用网页安装向导（会自动尝试建库，若无权限则回落到 `test` 库）。

## 目录结构

```
Tarsk/
├── app/
│   ├── bootstrap.php       配置、会话、鉴权、模板、日志
│   ├── db.php              PDO、表结构、种子数据
│   ├── system.php          跨平台系统适配（指标/服务/软件/防火墙/Docker/MySQL）
│   ├── routes.php          路由表
│   ├── controllers/        各功能控制器
│   └── views/              页面模板与布局
├── bin/
│   ├── install.php         CLI 初始化
│   └── reset-password.php  重置管理员密码
├── public/
│   ├── index.php           入口
│   ├── install.php         网页安装向导
│   ├── router.php          PHP 内置服务器路由
│   └── assets/             设计系统样式与脚本
├── storage/                缓存、证书、备份、日志（运行时生成）
└── config.php              配置（安装时生成）
```

## 运行提示

- **数据库权限**：面板默认使用配置中的 MySQL 账号。若该账号没有 `CREATE DATABASE` 权限，数据库页只能浏览已有库；在「面板设置」中填入 root 账号即可启用完整管理能力。
- **防火墙 / 计划任务**：写入系统规则或系统任务通常需要管理员权限，失败原因会直接显示在列表中。
- **Nginx 生效**：站点配置写入 `D:/BtSoft/nginx/conf/vhost`（可配置），修改后执行 `nginx -s reload` 生效。
- **Docker**：需要 Docker Desktop / Docker Engine 处于运行状态。
- **执行权限**：面板会调用 `proc_open` 执行系统命令，部署时请确保该函数未被 `disable_functions` 禁用。

## 安全建议

1. 生产环境请将 `config.php` 中 `app.debug` 置为 `false`。
2. 面板仅建议绑定内网或通过反向代理/HTTPS 暴露，不要直接对公网开放。
3. 定期在「操作日志」审阅高风险操作，并为面板配置强密码。
4. 面板以运行它的操作系统账号权限执行命令，请勿使用超级管理员以外的过高权限运行。

## License

MIT
