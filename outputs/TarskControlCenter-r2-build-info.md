# Tarsk Control Center r2 — 网站管理增强版

**程序：** `TarskControlCenter-20261113-r2.exe`。该版本从独立客户端源码 `desktop/tarsk_panel_v2.py` 构建，不使用旧 Tkinter 客户端代码或旧 EXE。

## 本轮完善

网站页面增加反向代理状态列及站点详情对话框，允许查看域名、站点目录、PHP/端口、HTTPS 状态，并在有写权限时编辑代理开关与目标。

新增 Bearer REST 接口 `GET /api/v1/sites/{id}` 与 `POST /api/v1/sites/{id}/proxy`。代理写接口要求 write scope、站点必须运行、Nginx 与配置测试器必须可用；校验目标地址，应用新 vhost 后测试配置并重载。配置测试/重载/数据库写入失败时恢复原 vhost，且配置应用成功前不更新代理数据库字段。客户端对变更提供二次确认。

## 验证

反代回归六项通过：成功应用、Nginx 配置测试失败回滚、reload 失败回滚、非法目标拒绝、站点停止时拒绝、只读 Token 拒绝。原站点启停与系统 Nginx 检测三项回归通过。115 个 PHP 文件语法检查通过；Python AST、Tk 界面创建/销毁和 DPAPI 加解密烟测通过；PyInstaller 构建完成并通过 PE 结构校验。

SHA-256：`C2771342F4A4FC435654DE968A301AD19EC2CA4BC0146D188EFC171E9C580AF2`

## 尚未覆盖

本轮没有宣称桌面端全功能迁移：站点创建/编辑/删除、Let's Encrypt 异步签发与证书管理、文件管理、软件商店、完整数据库 CRUD 等仍需 API 和桌面页面。完整差距见 `tarsk-desktop-parity-plan.md`。