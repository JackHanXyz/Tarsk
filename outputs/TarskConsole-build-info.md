# Tarsk Windows 远程控制台 — 新构建说明

- 产物：`TarskConsole-20261113.exe`
- 构建方式：PyInstaller one-file，从 `desktop/tarsk_console.py` 当前源码及静态 PNG/ICO 资源构建；构建输入复制到 `.work/desktop-rebuild/src` 隔离目录。
- 未使用旧 `desktop/dist/Tarsk控制台.exe` 作为输入，也未覆盖旧文件。
- 目标：Windows Tkinter 远程管理客户端，连接 Tarsk Web 面板的 REST API。
- 当前页面：总览、站点、数据库、备份、计划任务、告警、Nginx。不是 Web 面板完整迁移版。
- 首次运行：在 Web 面板「API Token」功能中创建只读 Token（需要改动时创建读写 Token），在客户端填写面板地址与 Token 后连接。只读与读写 Token 权限应按最小权限原则选择。
- 安全提示：当前客户端源码会把连接地址和 Token 写入 `%APPDATA%\\Tarsk\\console.json`。该文件为明文配置；在使用远程高权限读写 Token 前，应限制 Windows 账户访问，并规划迁移至 Windows Credential Manager。

完整的功能差距与 API/页面迁移清单见 `tarsk-desktop-parity-plan.md`。