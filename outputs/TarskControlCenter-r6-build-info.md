# Tarsk Control Center r6 构建说明

构建日期：2026-11-13。

## 构建来源与校验

| 项目 | 信息 |
|---|---|
| EXE | `TarskControlCenter-20261113-r6.exe` |
| 独立源码 | `desktop/tarsk_panel_v2.py` |
| 构建方式 | PyInstaller `--onefile --windowed`，Windows GUI 单文件程序 |
| 文件大小 | 12,234,778 bytes |
| SHA-256 | `D8F202A511DC7C880256937FE7C06776936F3986926E878073E73AB3F061A5FB` |
| 旧版依赖 | 不从旧版 EXE 解包、导入或复用旧桌面 UI；仍通过项目 REST API 与服务端交互 |

## 界面与交互

窗口移除了 Windows 系统标题栏，采用自绘无边框标题区；Windows 11 上会尽力请求 DWM 圆角。左侧红、黄、绿圆点分别负责关闭、最小化、最大化/还原，标题居中显示 `Tarsk Control Center`。标题区空白处可拖动，窗口边缘可手动缩放。

主界面采用 Google/Material 风格的白色与浅灰层次、Google 蓝色强调和浅蓝导航选中态。字体按系统可用情况优先选择 Roboto/Segoe UI Variable；本机实际烟测使用 `Microsoft YaHei UI` 与 `Cascadia Mono`，并保留中文字体回退。窗口标题区、侧栏与 EXE 共用 Tarsk 吉祥物；PNG 为 512×512，ICO 含 16、24、32、48、64、128、256 px 多尺寸。

## 验证结果

| 验证项 | 结果 |
|---|---|
| Python 源码与烟测脚本 | 语法检查通过 |
| 无边框与窗口控件 | `WS_CAPTION` 不存在；标题、三色 Canvas 按钮及按钮事件绑定通过 |
| 按钮与窗口行为 | 实际触发按钮事件后，最大化/还原、最小化/恢复通过；拖动和手动缩放通过 |
| 打包 EXE | PE MZ/PE 结构通过；GUI 启动、响应正常；实际截图确认 Mac/Material 风格 |
| 图标资源 | Windows 图形 API 可读取 ICO；包含 7 种分辨率 |
| 本地 PHP 预览 | 通过 `public/router.php` 在 `127.0.0.1:8889` 运行；访问 `/` 重定向至 `/login`，返回 HTTP 200 |

## 功能边界

r6 是独立的远程管理客户端，包含总览、站点、数据库、备份、计划任务、告警和 Nginx 页面。它仍不等于 Web 面板的全功能迁移版；当前 API 覆盖范围和剩余迁移项见 `tarsk-desktop-parity-plan.md`。