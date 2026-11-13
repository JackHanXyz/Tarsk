# Tarsk Control Center — 独立 UI 重构版

**新构建产物：** `TarskControlCenter-20261113.exe`。本版本从 `desktop/tarsk_panel_v2.py` 独立实现并使用 PyInstaller 重新构建；没有导入、启动、包装或复用旧 `tarsk_console.py` 的客户端逻辑，也没有使用旧 EXE 作为构建输入。旧版构建说明保留在 `TarskConsole-build-info.md`，仅供区分历史产物。

## 全新界面

桌面端采用重新设计的暖白工作区、森林绿导航、陶土色操作强调、清晰的层级标题、资源摘要卡片与表格。界面从零构建为总览、网站、数据库、备份中心、计划任务、告警记录和 Nginx 七个页面；不只是更换旧应用配色或重打包旧程序。

## 连接与安全

在「连接面板」中输入服务地址和 API Token。推荐使用 HTTPS 以及遵循最小权限原则的 Token。选择记住 Token 时，使用 Windows DPAPI 在当前 Windows 账户下加密保存到 `%APPDATA%\\TarskControlCenter\\connection.json`；不勾选则只在本次运行期间保存在内存中。

## 当前 API 覆盖边界

七个页面连接目前已有 REST API：主机状态、站点启停、数据库清单、站点/数据库备份与备份列表、计划任务立即执行、告警列表、Nginx 配置检测和重载。当前 API 尚未提供完整 Web 功能，因此站点创建/编辑、文件管理、软件商店、证书签发、数据库用户/SQL、计划任务增删改、用户与权限等仍未迁移。详细差距见 [桌面端迁移清单](tarsk-desktop-parity-plan.md)。

## 构建与验证

构建工具为 PyInstaller 6.22.3 / Python 3.13，Windows GUI one-file。源文件语法检查、Tk 页面创建/销毁烟测和 Windows DPAPI 加解密往返测试通过；打包后进程启动验证通过，PE 结构检查通过。

SHA-256：`B2D89AC9118994376A371E26375E718D7AB1740639823C73A59EDE4D2708D942`
