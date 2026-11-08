# Tarsk · 服务器运维面板

Tarsk 是使用 PHP + MySQL 编写的服务器管理面板，目标是对宝塔面板的核心功能做完整复刻：一键安装、运行环境托管、站点、文件、数据库、监控、计划任务、防火墙、Docker、SSL、软件商店、Web 终端、进程管理、回收站、两步验证、一键部署、FTP、插件市场与多版本 PHP 并行。

## 功能

### 安装与运行环境
- **一键安装**：`install-online.sh` / `install-online.ps1` 云端一键安装与升级（GitHub 源码、离线包、代理、保留配置），面板内「版本更新」可检查并一键升级。
- **Windows 一键安装**：`install.ps1` 自动检查 PHP 扩展、初始化数据库、生成管理员、注册登录自启任务并启动面板。
- **Linux 一键安装**：`install.sh` 生成 systemd 服务并启动。
- **托管运行环境**：在软件商店内真实下载、解压、配置、启动、停止、重启、卸载 **Nginx / PHP-CGI / Redis**，安装到 `storage/soft`，不污染系统目录，无需管理员权限。
- **多版本 PHP 并行**：PHP 7.4 / 8.0 / 8.1 / 8.2 / 8.3 / 8.4 可同时安装、同时运行（各自独立端口 9074~9084），每个站点可独立选择 PHP 版本。
- **端口隔离**：托管 Nginx 默认 `8080`、PHP-CGI `9000`、Redis `6380`，与系统已有服务并存。

### 站点
- 一键创建运行目录、默认首页、Nginx vhost，可选 **同时创建 MySQL 数据库**。
- **伪静态预设**：WordPress / Laravel / ThinkPHP / Discuz! / DedeCMS。
- **多域名绑定与站点高级项**：主域名 + 附加域名写入 `server_name`；默认文档自定义；**强制 HTTPS（301）**、**防盗链**（Referer 校验）、**防跨站 open_basedir** 一键开关。
- **反向代理**：站点一键反代到 `http://127.0.0.1:3000` 等本地服务（自动附带 Host / X-Forwarded-* 头），也可与 HTTPS 同时生效。
- **按站点切换 PHP 版本**：站点详情页选择已安装的托管版本，保存即自动启动并切换 FastCGI 通道。
- 站点详情页：修改域名、伪静态、状态，实时预览 vhost、查看访问日志、关联数据库与证书。
- 托管模式下保存即自动 `nginx -s reload`。

### 运维
- **文件管理**：浏览、新建目录/文件、在线编辑、上传、下载、重命名、删除，路径穿越防护；**压缩/解压（zip/tar，自动回退 bsdtar）**、**批量删除**、**权限修改（chmod）**、**URL 远程下载**。
- **回收站**：删除的文件先移入回收站，可还原、彻底删除或一键清空（可在设置中关闭）。
- **备份管理**：站点目录打包为 zip（PHP zip 扩展或系统 bsdtar）、数据库 mysqldump 导出，支持下载、删除、一键恢复，按「目标」保留最近 N 份。
- **一键部署**：WordPress 自动下载、解压、建库并写入 `wp-config.php`（含随机安全盐），打开站点即可完成安装向导。
- **数据库**：浏览库表、创建/删除数据库、mysqldump 备份、导入 root 账号解锁完整权限。
- **监控**：真实 CPU / 内存 / 磁盘 / 网卡采样，30 秒入库，24 小时曲线。
- **计划任务**：Windows `schtasks` / Linux `crontab` 注册、启停、立即执行；内置「站点备份 / 数据库备份 / 日志切割 / 证书续期 / 站点存活巡检」五类任务模板，也可执行自定义命令。
- **防火墙**：读取系统配置，写入/移除 Tarsk 规则（需管理员权限）。
- **Docker**：容器/镜像列表、启停、重启、删除、`docker run`。
- **SSL 证书**：OpenSSL 自签（含 SAN）、PEM 导入、**Let's Encrypt 免费证书自动签发（ACME v2 / HTTP-01，纯 PHP 实现）与到期自动续期**（剩余天数低于阈值时重签并回写 vhost）、到期提醒。
- **面板 SSL**：一键由托管 Nginx 以 HTTPS 反向代理面板（自签证书，可换正式证书）。
- **FTP 服务**：内置纯 PHP FTP 服务器（stream_select 多客户端、被动模式、账号主目录隔离），账号 CRUD 与一键启停。
- **插件市场**：本地插件目录（`app/plugins`）安装/启停/卸载，插件可注册路由与左侧导航，内置示例插件与 IP 黑名单插件（支持 `1.2.3.*` 通配，实时生效）。
- **Web 终端**：面板内真实执行系统命令，带会话历史与操作日志。
- **进程管理**：按内存排序查看进程，按 PID 结束。
- **软件商店**：托管环境安装管理 + 系统软件探测 / winget 静默安装。
- **安全体系**：安全入口路径（未登录访问一律 404）、TOTP 两步验证（兼容 Google / 微软 Authenticator）、**面板 IP 白名单（精确/通配/CIDR）**、**登录失败自动封禁**、CSRF、会话固定防护、操作日志审计与 CSV 导出。
- **通知与告警**：纯 PHP SMTP 邮件、飞书/钉钉/自定义 Webhook；CPU / 内存 / 磁盘阈值告警（带冷却），站点 HTTP 存活巡检失败自动推送。

## 环境要求

- PHP 8.1+（`pdo_mysql`、`openssl`、`mbstring`，建议开启 `proc_open`）
- MySQL 5.6+（面板数据使用 `tarsk_` 前缀）
- Windows 10/11 或主流 Linux 发行版
- 可选：Nginx、MySQL 客户端、Docker、winget

## 安装

### 云端一键安装（推荐）

**Linux**（自动下载 GitHub 最新源码、初始化、注册 systemd 并启动）：

```bash
curl -fsSL https://raw.githubusercontent.com/JackHanXyz/Tarsk/main/install-online.sh \
  | sudo bash -s -- --admin-pass 'YourPassword' --db-user root --db-pass 'dbpass'
```

**Windows**（PowerShell 5.1+，自动下载、解压、初始化并注册开机自启）：

```powershell
irm https://raw.githubusercontent.com/JackHanXyz/Tarsk/main/install-online.ps1 -OutFile "$env:TEMP\tarsk-install.ps1"
& "$env:TEMP\tarsk-install.ps1" -Dir 'C:\Tarsk' -DbUser root -DbPass 'dbpass' -AdminPass 'YourPassword'
```

常用参数：

| 参数（Linux / Windows） | 说明 |
|---|---|
| `--dir` / `-Dir` | 安装目录，Linux 默认 `/opt/tarsk`，Windows 默认 `C:\Tarsk` |
| `--upgrade` / `-Upgrade` | 升级模式：保留 `config.php`、`install.lock` 与 `storage`，重启服务 |
| `--local-zip` / `-LocalZip` | 使用离线源码包（内网或无外网环境） |
| `--branch` / `-Branch` | 拉取分支，默认 `main` |
| `--proxy` / `-Proxy` | 下载走指定代理，如 `http://127.0.0.1:10808` |

安装器只**检测** PHP 8.1+ 与 `pdo_mysql` / `openssl` / `mbstring` 扩展，缺失时给出安装命令并退出，不会修改系统依赖。

升级：

```bash
curl -fsSL https://raw.githubusercontent.com/JackHanXyz/Tarsk/main/install-online.sh | sudo bash -s -- --upgrade
```

```powershell
& "$env:TEMP\tarsk-install.ps1" -Dir 'C:\Tarsk' -Upgrade
```

安装完成后也可在面板「版本更新」页检查 GitHub 最新提交并一键更新（自动备份旧代码，保留配置与数据）。

### Windows 一键安装（本地脚本）

```powershell
# 在项目根目录执行
.\install.ps1 -DbUser root -DbPass "你的MySQL密码" -AdminPass "至少8位密码"
```

脚本会：检查 PHP 与扩展 → 写入 `config.php` → 建库/建表 → 创建管理员 → 注册 `Tarsk_Panel` 登录自启任务 → 启动面板并打开浏览器。

常用参数：`-DbHost`、`-DbPort`、`-DbName`、`-PanelPort`、`-NoTask`、`-Force`。

### Linux 一键安装

```bash
sudo ./install.sh --db-user root --db-pass "密码" --admin-pass "你的密码" --port 8888
```

以 root 运行时会写入 `/etc/systemd/system/tarsk.service` 并 `enable --now`。

### 网页安装向导

启动 PHP 内置服务器后访问 `/install`：

```bash
php -S 0.0.0.0:8888 -t public public/router.php
```

访问 `http://服务器IP:8888/install`，按向导填写数据库与管理员信息。若数据库账号没有建库权限，面板会自动回落到 `test` 库保证可用。

### 手动初始化

```bash
cp config.example.php config.php
# 编辑数据库连接
php bin/configure.php --host=127.0.0.1 --name=tarsk --user=root --pass=secret \
  --admin=admin --admin-pass=YourPassword --panel-port=8888
php -S 0.0.0.0:8888 -t public public/router.php
```

## 使用流程

1. 登录面板（默认端口 8888）。
2. 进入「软件商店」安装 Nginx 与 PHP（可选 Redis），启动它们。
3. 进入「网站」新建站点，绑定域名、选择伪静态，可选同时建库。
4. 托管 Nginx 通过 `http://服务器:8080` + `Host` 头访问站点；也可在设置中修改端口与系统 Nginx 共存。
5. 在「SSL 证书」签发或导入证书，站点配置自动启用 HTTPS。
6. 使用「Web 终端」「进程管理」「计划任务」「监控」完成日常运维。

## 目录结构

```
Tarsk/
├── app/
│   ├── bootstrap.php       配置、会话、鉴权、模板、日志、自动迁移
│   ├── db.php              PDO、表结构、增量迁移、种子数据
│   ├── system.php          跨平台系统指标/服务/软件/防火墙/Docker/MySQL
│   ├── runtime.php         Nginx / 多版本 PHP / Redis 下载安装与进程管理
│   ├── site_vhost.php      站点 vhost 生成、伪静态、按站点 PHP 版本
│   ├── acme.php            Let's Encrypt ACME v2 客户端（纯 PHP）
│   ├── backup.php          站点/数据库备份、恢复与保留策略
│   ├── tasks.php           计划任务执行器（备份/切割/续期/巡检/命令）
│   ├── security.php        登录失败封禁、IP 白名单匹配
│   ├── notify.php          邮件 SMTP / Webhook 通知与资源告警
│   ├── updater.php         在线升级：检查 GitHub 版本、下载覆盖与备份
│   ├── ftpd.php            纯 PHP FTP 服务器核心
│   ├── totp.php            RFC 6238 两步验证
│   ├── plugins.php         插件扫描/路由/导航/启动钩子
│   ├── panel_ssl.php       面板 HTTPS 反代
│   ├── routes.php          路由表
│   ├── plugins/            内置插件（demo、ipblock）
│   ├── controllers/        各功能控制器
│   └── views/              页面模板与布局
├── bin/
│   ├── configure.php       命令行配置 + 初始化
│   ├── install.php         CLI 初始化（使用已有 config）
│   ├── ftpd.php            FTP 守护进程入口
│   ├── task.php            计划任务 CLI 入口（php bin/task.php --id=N）
│   └── reset-password.php  重置管理员密码
├── public/
│   ├── index.php           入口（安全入口检查、插件路由合并）
│   ├── install.php         网页安装向导
│   ├── router.php          PHP 内置服务器路由
│   └── assets/             设计系统样式与脚本
├── storage/                缓存、日志、证书、回收站、软件安装目录（运行时生成，已忽略）
├── install.ps1             Windows 一键安装
├── install.sh              Linux 一键安装
├── install-online.ps1      Windows 云端安装 / 升级
├── install-online.sh       Linux 云端安装 / 升级
└── config.php              配置（安装时生成，已忽略）
```

## 说明与限制

- **数据库权限**：面板默认使用 `config.php` 中的 MySQL 账号。该账号若无 `CREATE DATABASE` 权限，数据库页只能浏览已有库；在「面板设置」中填写 root 账号即可解锁完整管理。
- **托管运行环境**：完整能力当前在 Windows 上实现（官方 ZIP + php-cgi + Redis for Windows）。Linux 端可继续使用系统包管理器，面板会探测并管理已有服务。
- **Nginx 配置隔离**：安装托管 Nginx 后，Tarsk 站点配置写入 `storage/soft/nginx/conf/vhosts`，运行在独立端口，不影响系统 Nginx；卸载时自动把站点配置同步回原 Nginx 目录。
- **系统级操作**：防火墙写入、结束进程、终端命令等依赖运行账号权限，失败原因会显示在界面并写入日志。
- **执行函数**：面板通过 `proc_open` 执行命令，部署时请勿禁用该函数。

## 安全建议

1. 生产环境将 `config.php` 中 `app.debug` 设为 `false`。
2. 在「面板设置 → 面板安全」配置安全入口并开启两步验证；面板仅绑定内网或置于 HTTPS 反向代理后。
3. 定期审阅「操作日志」，为面板设置强密码。
4. 终端与软件安装具有系统权限，请仅在可信环境使用。
5. 启用面板 SSL 后，建议把自签证书替换为正式证书（替换 `storage/ssl/panel/` 下的 cert.pem / privkey.pem 即可）。

## License

MIT
