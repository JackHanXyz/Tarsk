#!/usr/bin/env bash
# Tarsk 面板一键安装（Linux）
# 用法：sudo ./install.sh --admin-pass YourPassword [--db-name tarsk --db-user root --db-pass secret --port 8888]
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
PORT=8888
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=tarsk
DB_USER=root
DB_PASS=
ADMIN_USER=admin
ADMIN_PASS=

while [[ $# -gt 0 ]]; do
  case "$1" in
    --port) PORT="$2"; shift 2 ;;
    --db-name) DB_NAME="$2"; shift 2 ;;
    --db-user) DB_USER="$2"; shift 2 ;;
    --db-pass) DB_PASS="$2"; shift 2 ;;
    --db-host) DB_HOST="$2"; shift 2 ;;
    --db-port) DB_PORT="$2"; shift 2 ;;
    --admin) ADMIN_USER="$2"; shift 2 ;;
    --admin-pass) ADMIN_PASS="$2"; shift 2 ;;
    *) echo "未知参数：$1"; exit 1 ;;
  esac
done

if [[ -z "$ADMIN_PASS" ]]; then
  ADMIN_PASS=$(head -c 32 /dev/urandom | tr -dc 'A-Za-z0-9' | head -c 12)
fi

if ! command -v php >/dev/null 2>&1; then
  echo "未找到 PHP，请先安装 PHP 8.1+（例如：apt install php-cli php-mysql php-mbstring php-curl）"
  exit 1
fi

PHP_BIN=$(command -v php)
PHP_VER=$($PHP_BIN -r 'echo PHP_VERSION;')
echo "使用 PHP $PHP_VER ($PHP_BIN)"

for ext in pdo_mysql openssl mbstring; do
  if ! $PHP_BIN -m | grep -qi "^$ext$"; then
    echo "缺少 PHP 扩展：$ext"
    exit 1
  fi
done

echo "初始化数据库与面板配置..."
php "$ROOT/bin/configure.php" \
  --host="$DB_HOST" --port="$DB_PORT" --name="$DB_NAME" \
  --user="$DB_USER" --pass="$DB_PASS" \
  --admin="$ADMIN_USER" --admin-pass="$ADMIN_PASS" --panel-port="$PORT"

if [[ ! -d "$ROOT/storage" ]]; then mkdir -p "$ROOT/storage"; fi
chmod -R 0755 "$ROOT/storage" || true

if [[ $EUID -eq 0 ]] && command -v systemctl >/dev/null 2>&1; then
  echo "注册 systemd 服务 tarsk.service..."
  cat >/etc/systemd/system/tarsk.service <<EOF
[Unit]
Description=Tarsk Server Panel
After=network.target mysql.service

[Service]
Type=simple
WorkingDirectory=$ROOT
ExecStart=$PHP_BIN -S 0.0.0.0:$PORT -t public public/router.php
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
  systemctl daemon-reload
  systemctl enable tarsk --now
  echo "服务已启动：systemctl status tarsk"
else
  echo "未以 root 运行，跳过 systemd 注册。可手动启动："
  echo "  cd \"$ROOT\" && php -S 0.0.0.0:$PORT -t public public/router.php"
fi

echo ""
echo "面板地址：http://127.0.0.1:$PORT"
echo "管理员：$ADMIN_USER"
echo "密码：$ADMIN_PASS"
echo "下一步：登录后在「软件商店」安装 Nginx / PHP / Redis，或使用系统包管理器安装。"
