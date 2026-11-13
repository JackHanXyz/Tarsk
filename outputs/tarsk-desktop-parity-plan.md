# Tarsk Windows 桌面客户端定位与 Web 功能迁移清单

## 1. 产品定位

建议把 Windows EXE 定义为 **Tarsk 远程管理客户端**，而不是把服务器运维面板本身搬到用户电脑运行。服务器仍运行 Tarsk Web 服务、数据库以及 Nginx/系统服务；Windows EXE 通过 HTTPS REST API 管理一台或多台面板。这样权限、审计、任务执行和真实主机状态都留在被管理服务器上，桌面程序仅负责交互和安全传输。

“本机自包含控制面板”是另一种产品：需要捆绑 PHP、数据库/本地数据层、服务守护、升级器和安装卸载流程，且不少系统运维功能在另一台远程服务器上并不适用。两种定位不应混为一个 EXE。

## 2. 当前客户端与本次新构建的边界

当前桌面源码是独立实现的 Tkinter 远程 API 客户端；页面包括总览、站点、数据库、备份、计划任务、告警和 Nginx。r2 增加站点详情与反向代理配置；r6 更新为 Windows 无系统标题栏、Mac 风格红黄绿圆点控制、居中标题及 Google/Material 浅色蓝灰主题。现有 REST API 覆盖 `status`、`sites`（列表/详情/启停/反代配置）、`databases`（只读）、`backups`（列表/创建）、`tasks`（列表/执行）、`alerts`（只读）和 `nginx`（检测/重载）。

r6 仍从 `desktop/tarsk_panel_v2.py` 独立构建，没有读取、解包或复用旧 EXE/旧桌面 UI；运行时继续使用同步的 Tarsk 吉祥物头像与 Windows DPI-aware 中文字体回退。它仍是有限功能的远程管理客户端，并不等于 Web 面板全功能迁移版；本清单继续记录剩余迁移工作。

## 3. 全功能迁移需要补齐的 API 与桌面页面

所有写接口均需服务端再次执行角色授权、参数校验、路径/命令白名单、审计日志和状态一致性处理；客户端隐藏按钮不能代替后端授权。长操作应返回 job ID 并提供状态/日志查询，不能让桌面 UI 等待一个可能超时的 HTTP 请求。上传/下载需要流式传输、进度与续传；列表接口需要分页、过滤、排序。

| 功能域 / 桌面页面 | 需要补齐的 API（建议路径；按现有 REST 风格扩展） | 页面/交互要点 |
|---|---|---|
| 登录与面板连接 | `POST /api/v1/auth/session`、`POST /auth/refresh`、`DELETE /auth/session`、`GET /me`、`GET /capabilities`；或继续使用 Token，但需可安全录入/吊销与权限探测 | 多面板连接管理、连接测试、HTTPS/证书提示、Token 作用域与到期展示、凭据安全保存、退出/切换面板 |
| 总览与监控 | `GET /metrics?fresh=1`、`GET /metrics/history?range=`、`GET /services`、`GET /health` | CPU/内存/Swap/磁盘/网卡/磁盘 I/O、实时趋势、刷新状态、服务状态、告警摘要；指标应带采样时间和单位 |
| 网站 | **r2 已实现：** `GET /api/v1/sites/{id}`、`POST /api/v1/sites/{id}/proxy`；**待补：** `POST /sites`、`PATCH/DELETE /sites/{id}`、状态/伪静态/vhost/日志/deploy 接口 | r2 已支持详情与带配置测试/失败回滚的反代设置；仍待列表搜索、创建编辑、HTTPS、日志、配置预览和安全删除 |
| SSL 证书 | `GET/POST /certificates`、`POST /certificates/issue`、`POST /certificates/upload`、`POST /certificates/{id}/renew`、`DELETE /certificates/{id}`、`POST /sites/{id}/certificates/{certId}` | 证书列表、申请/续签进度、证书详情/到期提醒、上传、站点绑定和失败回滚结果 |
| 文件管理与回收站 | `GET /files?path=`、`GET/PUT /files/content`、`POST /files`、`POST /files/upload-sessions`、`PUT /files/upload-sessions/{id}/parts/{n}`、`POST /files/upload-sessions/{id}/complete`、`GET /files/download`、`POST /files/rename|chmod|archive|extract|remote-download`、`DELETE /files`、`GET /recycle`、`POST /recycle/{id}/restore`、`DELETE /recycle/{id}` | 文件浏览器、编辑器、上传/断点续传/下载、压缩解压、权限、路径安全提示、批量删除、回收站恢复/清理 |
| 文件安全扫描 | `POST /security/baselines`、`GET /security/baselines/{id}`、`POST /security/scans`、`GET /security/scans/{id}`、`GET /security/scans/{id}/findings` | 基线创建/差异对比、Webshell 扫描任务、结果筛选、风险详情；结果须分页并可导出 |
| 数据库 | `GET/POST /databases`、`GET/DELETE /databases/{name}`、`GET /databases/{name}/tables`、`POST /databases/{name}/import`、`GET /databases/{name}/export`、`GET /databases/{name}/processes`、`POST /databases/processes/{id}/kill` | 数据库列表/创建删除、表信息、导入导出进度、连接状态、权限不足说明 |
| 数据库用户/性能/复制 | `GET/POST /database-users`、`PATCH/DELETE /database-users/{id}`、`GET /databases/{name}/slow-queries`、`POST /database/slow-log`、`GET /database/replication`、`GET /database/tuning`、`PATCH /database/tuning` | 用户与授权、密码重置、远程访问、慢查询报表、在线参数调优、主从状态；高风险改动需二次确认/审计 |
| 备份与异地备份 | `POST /backups`、`GET /backups`、`GET /backups/{id}/download`、`POST /backups/{id}/restore`、`DELETE /backups/{id}`、`GET/POST/PATCH/DELETE /backup-targets`、`POST /backup-targets/{id}/test`、`POST /backups/{id}/push` | 站点/数据库备份、下载/恢复、保留策略、S3/OSS/WebDAV 配置、连通性和任务日志 |
| 计划任务 | `GET/POST /tasks`、`GET/PATCH/DELETE /tasks/{id}`、`POST /tasks/{id}/status`、`POST /tasks/{id}/run`、`GET /tasks/{id}/runs`、`DELETE /tasks/{id}/runs` | 任务列表、模板、自定义 cron、启停/运行、执行历史、输出查看和清理 |
| 防火墙 | `GET /firewall/status`、`GET/POST /firewall/rules`、`DELETE /firewall/rules/{id}`、`POST /firewall/rules/{id}/status` | 后端类型/默认策略、入站出站规则、添加/删除/启停、应用失败提示；防止误锁远程管理端口 |
| 软件商店与运行环境 | `GET /software`、`POST /software/{key}/check`、`POST /software/{key}/install`、`GET /jobs/{id}`、`GET /jobs/{id}/logs`、`POST /runtimes/{key}/actions/{start|stop|restart|uninstall}` | 软件目录、真实安装检测、后台安装进度/日志、启停卸载、系统包与托管运行时区分 |
| PHP/Nginx/MySQL/Redis 环境配置 | `GET/PUT /environments/php`、`GET/PUT /environments/php/extensions`、`GET/PUT /environments/nginx`、`POST /environments/nginx/test|reload|rollback`、`GET/PUT /environments/mysql`、`GET/PUT /environments/redis` | 参数表单、原始配置编辑、校验、差异预览、备份与回滚、服务重启；敏感值脱敏 |
| Docker | `GET /docker/containers`、`GET /docker/images`、`POST /docker/containers`、`POST /docker/containers/{id}/actions/{start|stop|restart}`、`DELETE /docker/containers/{id}`、`POST /docker/images/{id}/pull` | 容器/镜像、端口/卷/环境变量配置、启停删除、镜像拉取日志；高风险清理二次确认 |
| 终端与进程 | `POST /terminal/sessions`、`POST /terminal/sessions/{id}/input`、`GET /terminal/sessions/{id}/output`、`DELETE /terminal/sessions/{id}`、`GET /processes`、`POST /processes/{pid}/kill` | WebSocket/受限流式终端、命令审计、会话生命周期、进程筛选/排序/结束；不要提供绕过权限的任意命令代理 |
| 项目/应用托管/Git 部署 | `GET/POST /projects`、`GET/PATCH/DELETE /projects/{id}`、`POST /projects/{id}/actions/{start|stop|restart}`、`GET /projects/{id}/logs`、`POST /projects/{id}/deploy`、`POST /webhooks/test` | Node/Python/Java 项目配置、环境变量、探活、开机自启、日志、Git 分支/部署历史/Webhook |
| 日志与告警 | `GET /logs`、`GET /logs/analysis`、`GET /alerts`、`GET /alerts/{id}`、`POST /alerts/cleanup`、`GET/PUT /notifications`、`POST /notifications/test` | 操作/访问/错误日志筛选导出、统计图、告警历史、阈值/冷却设置、邮件/Webhook 测试 |
| FTP | `GET/POST /ftp/users`、`PATCH/DELETE /ftp/users/{id}`、`GET /ftp/status`、`POST /ftp/actions/{start|stop}` | 用户主目录隔离、密码重置、服务状态、启动停止和端口说明 |
| 系统工具 | `GET /system/overview`、`GET /system/disks`、`GET/PUT /system/timezone`、`GET /system/ssh`、`POST /system/optimizations` | 主机信息、盘符/挂载点、时区、Swap、电源/休眠/SSH 状态；区分只读和管理员操作 |
| 用户、安全与 API Token | `GET/POST /users`、`PATCH/DELETE /users/{id}`、`POST /users/{id}/password-reset`、`GET/PUT /security/settings`、`GET/POST/DELETE /tokens` | 用户角色/启停/密码管理、安全入口、IP 白名单、TOTP、登录封禁、Token 管理；令牌必须绑定有效用户及当前角色 |
| 插件与升级 | `GET /plugins`、`POST /plugins/{id}/actions/{enable|disable|uninstall}`、`GET /updates`、`POST /updates/apply` | 插件列表/状态/兼容性、版本检查、升级备份/进度/失败回滚 |
| 操作审计与任务中心 | `GET /audit-logs`、`GET /jobs`、`GET /jobs/{id}`、`GET /jobs/{id}/logs`、`POST /jobs/{id}/cancel` | 统一审计、异步任务、取消、进度与日志；客户端断线后仍可恢复查看任务 |

## 4. API 设计验收条件

- 每个写请求由服务端按当前账号角色和作用域授权；Token 失效、用户停用、权限降级应立即生效。
- 统一 `{ok,data,error,message,request_id}` 响应结构及 400/401/403/404/409/422/429/500 状态码；失败不得伪报成功。
- 写操作在文件/配置/数据库/服务之间保持一致；配置测试失败需回滚，删除与重启结果要返回真实状态。
- 长任务返回 job ID、阶段、可轮询状态、增量日志和取消能力；限流并限制并发。
- 对任意文件路径、Shell 命令、SQL、URL 下载、Docker 参数做服务器端白名单/边界检查；凭据加密存储、日志脱敏。
- 下载/上传使用短时授权、CSRF/Origin 或专用 Token 校验；桌面端配置不应明文保存长效 Token，优先使用 Windows Credential Manager。
- 先完成 API contract tests 和关键危险操作集成测试，再逐模块迁移 UI；保留 Web 端并行运行和审计能力。

## 5. 建议实施阶段

1. **底座**：API 版本化、认证/能力发现、统一错误格式、任务中心、分页、审计、自动化测试。
2. **高频管理**：总览监控、网站/SSL、文件、数据库/备份、计划任务。
3. **系统运维**：防火墙、软件商店/环境配置、Docker、系统工具、日志/告警。
4. **扩展与高风险能力**：终端/进程、FTP、项目托管/Git、插件、升级、用户与安全设置；逐项做权限和安全审查。

只有每个 Web 功能均有等价 API、桌面页面、错误/权限处理和自动化验收后，才可称为“全功能桌面客户端”。
