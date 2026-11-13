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
- **单站上传大小**：`client_max_body_size` 按站点设置（`64m` / `1g` 等），未设置时回落到全局值。
- **防 CC / 流量限制**：按站点设置请求速率（`limit_req`，r/s + burst）与单 IP 并发连接数（`limit_conn`），共享内存区由面板按站点速率自动生成。
- **HTTP/2 与 HSTS**：443 站点可开启 HTTP/2（nginx ≥ 1.25.1 自动使用 `http2 on;`，旧版回落到 `listen ... http2`）与 `Strict-Transport-Security`（1 年 + includeSubDomains）。
- **反向代理**：站点一键反代到 `http://127.0.0.1:3000` 等本地服务（自动附带 Host / X-Forwarded-* 头），也可与 HTTPS 同时生效。
- **按站点切换 PHP 版本**：站点详情页选择已安装的托管版本，保存即自动启动并切换 FastCGI 通道。
- 站点详情页：修改域名、伪静态、状态，实时预览 vhost、查看访问日志、关联数据库与证书。
- **站点自定义配置**：站点详情页可直接编辑一段自定义 Nginx 片段（`add_header` / `location` / `rewrite` 等），自动 include 进该站点每个 server 块；保存前 `nginx -t` 校验，失败立即回滚，重新保存站点常规配置不会覆盖它。
- 托管模式下保存即自动 `nginx -s reload`。

### 环境配置（在线改参数，不用手改 ini）

- **PHP 参数**：可视化编辑 `php.ini` 的 22 项核心参数（内存 / 上传 / 执行时间 / 输入变量 / 错误日志 / OPcache / 会话 / 时区），也可直接编辑原始 ini。
- **改动必校验**：每次保存先做语法检查，再调用 `php-cgi -c <临时 ini> -v` 真实加载一遍；未通过直接拒绝写入，原文件不动。通过后才备份、写入并按需重启 PHP-CGI。
- **PHP 扩展**：扫描 `ext` 目录列出全部扩展，勾选即启停（自动区分 `extension` / `zend_extension`），文件缺失的条目会从配置中清除避免启动告警；批量保存只重启一次。
- **Nginx 全局**：worker 数量 / 单进程连接数 / keepalive / 上传上限 / gzip（级别与类型）/ 隐藏版本号 / 访问日志 / sendfile 可视化调整，保存时重建 `conf/nginx.conf` 并 `nginx -t`，失败自动回滚；也可直接编辑原始配置，页面上提供历史回滚点。
- **站点限流区自动生成**：`conf/tarsk-zones.conf` 按各站点速率生成 `limit_req_zone`；老版本安装的 `nginx.conf` 缺少 include 时自动补全（补全后同样过 `nginx -t`）。
- **MySQL 状态与调优**：QPS、连接数占用、慢查询占比、Buffer Pool 命中率、临时表 / 全表扫描 / 锁等待计数；`SHOW FULL PROCESSLIST` 可终止连接；10 项常用参数支持 `SET GLOBAL` 在线调整。
- **Redis 管理**：原生 RESP 协议直连（不依赖 phpredis），内存与命中率、`SCAN` 按模式浏览键（类型 / TTL / 内存占用）、批量删除、清空当前库；INFO 原文按分区折叠查看。

### 日志分析

- **访问日志报表**：解析 Nginx combined 格式（站点日志 / 全局 access.log），输出 PV、UV、出站流量、平均响应大小、错误率、蜘蛛占比。
- **维度拆解**：状态码分布（2xx/3xx/4xx/5xx + Top 状态码）、Top 15 URL（自动去掉 query）、Top 10 客户端 IP、Top 10 来源域名（Referer）、错误请求 Top 10、按小时请求趋势。
- **蜘蛛识别**：内置百度 / Google / Bing / 搜狗 / 360 / 神马 / Yandex / 字节 / 华为 / OpenAI / Claude 等 UA 规则，脚本类客户端（curl、python-requests）单独归类。
- **大文件友好**：只读取日志尾部指定大小（默认 8 MB，可调至 64 MB），支持「全部 / 最近 1 小时 / 24 小时 / 7 天」时间窗过滤，不阻塞面板。

### 运维

- **文件管理**：浏览、新建目录/文件、在线编辑、上传、下载、重命名、删除，路径穿越防护；**压缩/解压（zip/tar，自动回退 bsdtar）**、**批量删除**、**权限修改（chmod）**、**URL 远程下载**。
- **大文件分片上传**：2 MB 切片直传，带进度条；分片会话保留 12 小时，重新选择同一文件自动跳过已上传分片实现**断点续传**，合并后校验大小一致才落盘。
- **文件安全**：为站点目录建立 sha256 基线做**篡改检测**（新增 / 修改 / 删除分列），并按 12 条特征规则**扫描 Webshell / 木马**（`eval($_POST)`、动态包含、`preg_replace /e`、伪装成图片的 PHP 等）；无法读取的文件会单独列出——读不出来本身就是可疑信号。
- **回收站**：删除的文件先移入回收站，可还原、彻底删除；支持**勾选批量删除**与**按时间批量清理**（如清 30 天前），并按 24 小时 / 7 天 / 30 天分桶展示占用（可在设置中关闭）。
- **备份管理**：站点目录打包为 zip（PHP zip 扩展或系统 bsdtar）、数据库 mysqldump 导出，支持下载、删除、一键恢复，按「目标」保留最近 N 份。
- **异地备份**：把本地备份推送到 **S3（SigV4 签名）/ 阿里云 OSS（HMAC-SHA1）/ WebDAV**，支持连通性测试与上传结果记录；签名全部在本地计算，不依赖任何 SDK。
- **一键部署**：支持 **WordPress / Typecho / Discuz! X / Nextcloud**。自动下载、解压（支持只取 `upload/` 子目录）、建库并准备可写目录；WordPress 会写入 `wp-config.php`（含随机安全盐）。建库失败时给出警告级提示而不是假成功。
- **数据库**：浏览库表、创建/删除数据库、mysqldump 备份、导入 root 账号解锁完整权限。
- **数据库用户与权限**：查看全部账号（含 host 与逐条 `SHOW GRANTS`）、按库授权创建账号（全部 / 读写结构 / 仅读写 / 只读）、重置密码、**远程访问开关**（`RENAME USER` 在 localhost 与 `%` 之间切换并保留授权）；当前管理账号权限不足时页面会直接指出缺什么权限。
- **SQL 文件导入**：上传 `.sql` 导入指定库，优先调用 `mysql` 客户端（支持 `DELIMITER` 与存储过程），无客户端时回落到自研语句拆分器（正确处理字符串、反引号与三种注释里的分号）。
- **慢查询报表**：解析 `slow_query_log_file`，按 SQL 模板（字面量归一化为 `?`、统一小写）聚合，给出次数 / 总耗时 / 平均 / 最大 / 平均扫描行，并支持一键开关慢日志与调整阈值。
- **主从与同步**：展示 `SHOW MASTER STATUS` / `SHOW SLAVE STATUS`、binlog 配置与延迟，生成 my.cnf 片段与 `CHANGE MASTER` 命令；**不自动改配置**——复制拓扑属于高风险操作，留给维护窗口手工执行。
- **监控**：真实 CPU / 内存 / 磁盘 / 网卡采样，30 秒入库，24 小时曲线。
- **计划任务**：Windows `schtasks` / Linux `crontab` 注册、启停、立即执行；内置「站点备份 / 数据库备份 / 日志切割 / 证书续期 / 站点存活巡检」五类任务模板，也可执行自定义命令。
- **自定义 cron 表达式**：除 6 种固定频率外，支持标准 5 段式（分 时 日 月 周），可用 `*` / `*/n` / `a-b` / `a,b` 组合，并支持 `@daily` / `@hourly` / `@weekly` / `@monthly` / `@reboot`。Linux 直接写入 crontab；Windows 用「每分钟触发 + 到期判断」模拟（精度到分钟）。
- **任务执行历史**：每次执行（调度或手动）都落库，记录结果、耗时、触发方式与完整输出，每任务保留最近 200 条，可翻页查看与清空。
- **防火墙**：读取系统配置，写入/移除 Tarsk 规则（需管理员权限）。
- **Docker**：容器/镜像列表、启停、重启、删除、`docker run`。
- **SSL 证书**：OpenSSL 自签（含 SAN）、PEM 导入、**Let's Encrypt 免费证书自动签发（ACME v2 / HTTP-01，纯 PHP 实现）与到期自动续期**（剩余天数低于阈值时重签并回写 vhost）、到期提醒。
- **面板 SSL**：一键由托管 Nginx 以 HTTPS 反向代理面板（自签证书，可换正式证书）。
- **FTP 服务**：内置纯 PHP FTP 服务器（stream_select 多客户端、被动模式、账号主目录隔离），账号 CRUD 与一键启停。
- **插件市场**：本地插件目录（`app/plugins`）安装/启停/卸载，插件可注册路由与左侧导航，内置示例插件与 IP 黑名单插件（支持 `1.2.3.*` 通配，实时生效）。
- **Web 终端**：面板内真实执行系统命令，带会话历史与操作日志。
- **进程管理**：按内存排序查看进程，按 PID 结束。
- **软件商店**：托管环境安装管理 + 系统软件探测 / winget 静默安装；PHP 7.4–8.4 六个版本并存，站点级选择所用版本。
- **应用托管**：以分离进程方式托管 **Node.js / Python / Java** 项目——启动、停止、实时探活、stdout/stderr 日志分页查看、开机自启（Windows 任务计划程序 ONSTART / crontab @reboot）。Windows 下若环境变量存在大小写重复键导致 Start-Process 失败，自动回退 ShellExecute + 启动器脚本。
- **Git 部署**：站点绑定仓库（地址 + 分支 + 随机 secret）；手动拉取或 **Webhook 自动拉取**（`POST /webhook/<secret>`，免登录，兼容 GitHub `X-Hub-Signature-256` 签名校验与 Gitee/GitLab 的 ref 匹配；首次 clone 要求站点目录为空，后续 fetch + reset 同步）。
- **系统工具**：时区查看与设置、磁盘 / 挂载点使用率、swap / 页面文件状态（Linux 可在线调 swappiness 并持久化）、SSH 服务状态、常用系统优化（电源计划、关闭休眠、swappiness）。
- **多用户与角色权限**：`admin` / `operator` / `viewer` 三级角色，按「方法 + 路径」在路由分发处统一判定，未授权直达链接返回 403、侧边栏自动隐藏入口；支持新建用户、改角色、启停、重置密码，禁止自我降权并强制保留至少一个启用中的管理员；停用账号无法登录。
- **REST API + Token**：`/api/v1` 提供状态、站点、数据库、备份、任务、告警、Nginx 等接口；Bearer Token 分 `read` / `write` 两种 scope，令牌只存 SHA-256 摘要、只在创建时显示一次，可设有效期并随时吊销。
- **安全体系**：安全入口路径（未登录访问一律 404）、TOTP 两步验证（兼容 Google / 微软 Authenticator）、**面板 IP 白名单（精确/通配/CIDR）**、**登录失败自动封禁**、CSRF、会话固定防护、操作日志审计与 CSV 导出。
- **通知与告警**：纯 PHP SMTP 邮件、飞书/钉钉/自定义 Webhook；CPU / 内存 / 磁盘阈值告警（带冷却），站点 HTTP 存活巡检失败自动推送。
- **告警历史与趋势**：所有告警（资源阈值按 CPU / 内存 / 磁盘分别记录）都会落库，即使未配置通知渠道也能回溯；「告警历史」页按类型、级别、时间筛选，附 14 天趋势图，支持按 30 / 90 天或全量清理，自动保留 180 天（上限 5000 条）。

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

> 若 `8888` 已被其他程序（例如宝塔面板）占用，安装脚本会自动改用 `8890-8920` 中的空闲端口，并在结尾打印实际面板地址；也可用 `-PanelPort` 手动指定。

### Linux 一键安装

```bash
sudo ./install.sh --db-user root --db-pass "密码" --admin-pass "你的密码" --port 8888
```

以 root 运行时会写入 `/etc/systemd/system/tarsk.service` 并 `enable --now`。

### 网页安装向导

启动 PHP 内置服务器后访问 `/install`：

```bash
php -S 127.0.0.1:8888 -t public public/router.php
```

访问 `http://127.0.0.1:8888/install`，按向导填写数据库与管理员信息。若数据库账号没有建库权限，面板会自动回落到 `test` 库保证可用。需要远程访问时，请经 HTTPS 反向代理访问，不要直接暴露 PHP 内置服务器。

### 手动初始化

```bash
cp config.example.php config.php
# 编辑数据库连接
php bin/configure.php --host=127.0.0.1 --name=tarsk --user=root --pass=secret \
  --admin=admin --admin-pass=YourPassword --panel-port=8888
php -S 127.0.0.1:8888 -t public public/router.php
```

生产环境请通过 HTTPS 反向代理对外提供面板访问；不要将 PHP 内置服务器直接暴露到公网。

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
│   ├── envconfig.php       在线改配置：php.ini / PHP 扩展 / Nginx 全局 / MySQL / Redis
│   ├── loganalytics.php    访问日志解析与聚合（PV/UV、状态码、Top URL、来源、蜘蛛）
│   ├── dbadmin.php         数据库用户/授权、SQL 导入、慢查询聚合、复制状态
│   ├── filesecurity.php    文件完整性基线比对与 Webshell 特征扫描
│   ├── authz.php           角色与权限矩阵、路由级权限判定
│   ├── api.php             REST API 分发与 Token 校验
│   ├── remote.php          S3 / OSS / WebDAV 异地备份与签名
│   ├── projects.php        Node/Python/Java 应用进程托管（启动/探活/日志/自启）
│   ├── gitdeploy.php       Git 拉取部署与 Webhook 分发
│   ├── sysadmin.php        系统工具（时区/磁盘/swap/SSH/优化）
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
