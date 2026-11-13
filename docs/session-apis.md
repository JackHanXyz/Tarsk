# 网站与软件商店会话 API

这些端点面向 Tarsk 管理界面及可信的同源前端调用，使用**已登录的 PHP 会话**，不是 `/api/v1` Bearer Token API。请求继续经过面板 IP 白名单和 RBAC：网站写操作要求 `site.manage`；软件商店操作要求 `system.manage`。所有 POST 必须提供当前会话的 CSRF token。

## CSRF 与请求格式

先使用已登录会话 GET 相应列表端点，响应中含 `csrf_token`。POST 使用 `FormData`（或 `application/x-www-form-urlencoded`），字段名为 `_token`；同时发送浏览器会话 Cookie。返回体为 UTF-8 JSON。不要将 CSRF token 写进 URL 或日志。

```js
const state = await fetch('/sites/api', { credentials: 'same-origin' }).then(r => r.json());
const form = new FormData();
form.set('_token', state.csrf_token);
form.set('name', 'docs');
form.set('domain', 'docs.example.com');
const response = await fetch('/sites/api/create', {
  method: 'POST', credentials: 'same-origin', body: form
});
const result = await response.json();
if (!response.ok || !result.ok) throw new Error(result.message || '创建失败');
```

## 网站

| 方法与路径 | 用途 | 关键字段 |
|---|---|---|
| `GET /sites/api` | 返回真实站点记录、目录存在状态、目录大小和有效端口 | 响应：`items[]`、`count`、`csrf_token` |
| `POST /sites/api/create` | 创建站点目录、默认首页、数据库记录及 Nginx 虚拟主机 | `_token`、`name`；可选 `domain`、`php_version`、`rewrite`、`remark` |
| `POST /sites/api/{id}/update` | 更新站点基础配置并同步 Nginx | `_token`；可选 `domain`、`php_version`、`rewrite`、`remark`、`status` |
| `POST /sites/{id}/proxy` | 快捷启用/关闭反向代理；写入 vhost、检查配置并 reload，失败回滚 | `_token`、`proxy_pass`；关闭时 `action=disable` |
| `POST /sites/api/{id}/toggle` | 切换站点运行/停止并更新 Nginx 配置 | `_token` |
| `POST /sites/api/{id}/delete` | 删除站点记录与 Nginx 配置 | `_token`；仅当显式传 `delete_files=1` 时删除站点目录及文件 |

创建时验证站点名和域名；更新接受部分字段，不提供的字段保持原值。启停/更新只有在 Nginx 配置操作成功后才提交数据库变更。删除文件会解析实际路径并要求其位于配置的站点根目录下，拒绝删除站点根目录本身或根目录外路径。若 Nginx 操作失败，接口返回错误而不谎报成功。站点详情另有 Let's Encrypt 快捷入口：HTTP-01 要求 DNS 指向本机且公网 80 端口可达；签发前应用 challenge location，签发后先测试新证书配置，再 reload，失败则恢复原配置和 SSL 状态。

## 软件商店

| 方法与路径 | 用途 | 关键字段 |
|---|---|---|
| `GET /store/api` | 返回实时托管服务状态、系统软件检测结果、平台与 winget 能力 | 响应：`managed`、`apps`、`platform`、`winget_available`、`csrf_token` |
| `POST /store/api/check` | 检测单项或全部软件，并将探测结果写回 `soft` 表 | `_token`、`key`（软件 key 或 `all`） |
| `POST /store/api/action` | 托管运行环境启停、重启、卸载或打开目录 | `_token`、`key`、`action` (`start`/`stop`/`restart`/`uninstall`/`open`) |
| `POST /store/api/install` | 安装托管运行环境或发起 winget/Linux 包管理器系统软件安装 | `_token`、`key` |
| `GET /store/install-status?key={key}` | 查询后台任务状态、退出码与最近日志 | 已登录会话 Cookie |

安装会改变服务器状态，应由明确的管理员操作触发。托管包安装当前只支持 Windows；Windows 系统软件安装依赖 winget 和 catalog 中的 package ID。Linux 支持检测到的 apt、dnf、yum、apk、pacman、zypper，软件包名来自代码白名单映射，且要求 Tarsk 服务以 root 运行；面板不会自动调用 sudo。系统软件安装返回 `202` 表示后台任务已启动，可通过 `GET /store/install-status` 查询状态和日志。托管安装沿用原有同步安装器。

## 响应与错误

成功响应均含 `ok: true`。错误响应含 `ok: false` 和可展示的 `message`。常见状态码：`201` 创建成功、`202` 系统软件安装已发起、`404` 资源不存在、`409` 名称或目标冲突、`422` 参数校验失败、`502` 文件系统/Nginx/外部进程操作失败、`501` 当前平台不支持。未登录、权限不足和 CSRF 失败继续由面板统一中间件处理。
