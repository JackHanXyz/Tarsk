#!/usr/bin/env bash
# Tarsk 面板云端安装 / 升级（Linux）
#
# 首次安装：
#   curl -fsSL https://raw.githubusercontent.com/JackHanXyz/Tarsk/main/install-online.sh | sudo bash -s -- --admin-pass 'YourPassword'
#
# 升级到最新代码（保留 config.php 与 storage）：
#   curl -fsSL https://raw.githubusercontent.com/JackHanXyz/Tarsk/main/install-online.sh | sudo bash -s -- --upgrade
#
# 可选参数：--dir /opt/tarsk  --branch main  --port 8888  --db-user root --db-pass secret --db-name tarsk --admin admin --admin-pass secret

set -euo pipefail

REPO_SLUG="JackHanXyz/Tarsk"
BRANCH=main
DEST=/opt/tarsk
UPGRADE=0
LOCAL_ZIP=""
ARGS=()

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dir) DEST="$2"; shift 2 ;;
    --branch) BRANCH="$2"; shift 2 ;;
    --upgrade) UPGRADE=1; shift ;;
    --local-zip) LOCAL_ZIP="$2"; shift 2 ;;
    --port|--db-name|--db-user|--db-pass|--db-host|--db-port|--admin|--admin-pass)
      ARGS+=("$1" "$2"); shift 2 ;;
    *) echo "未知参数：$1"; exit 1 ;;
  esac
done

sep() { printf '%.0s=' {1..58}; echo; }

sep
echo " Tarsk 面板云端安装"
sep

# ---------- 依赖检测 ----------
if ! command -v php >/dev/null 2>&1; then
  echo "[错误] 未找到 PHP。请先安装 PHP 8.1+ 与常用扩展："
  echo "       Debian/Ubuntu: apt install -y php-cli php-mysql php-mbstring php-curl php-xml php-zip"
  echo "       RHEL/CentOS:   yum install -y php-cli php-mysqlnd php-mbstring php-curl"
  exit 1
fi

PHP_BIN=$(command -v php)
PHP_VER=$($PHP_BIN -r 'echo PHP_VERSION;')
echo "[1/5] PHP $PHP_VER ($PHP_BIN)"

if $PHP_BIN -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);' ; then
  :
else
  echo "[错误] PHP 版本过低（$PHP_VER），需要 8.1 及以上"
  exit 1
fi

MISSING_EXTS=""
for ext in pdo_mysql openssl mbstring; do
  if ! $PHP_BIN -m | grep -qi "^${ext}$"; then
    MISSING_EXTS="$MISSING_EXTS $ext"
  fi
done
if [[ -n "$MISSING_EXTS" ]]; then
  echo "[错误] 缺少 PHP 扩展：$MISSING_EXTS"
  echo "       Debian/Ubuntu 示例：apt install -y php-mysql php-mbstring php-curl"
  exit 1
fi
echo "[2/5] PHP 扩展检查通过"

DOWNLOADER=""
if command -v curl >/dev/null 2>&1; then
  DOWNLOADER="curl"
elif command -v wget >/dev/null 2>&1; then
  DOWNLOADER="wget"
else
  echo "[错误] 需要 curl 或 wget 下载源码包，请先安装其中之一"
  exit 1
fi

UNPACK=""
if command -v unzip >/dev/null 2>&1; then
  UNPACK="unzip"
elif command -v python3 >/dev/null 2>&1; then
  UNPACK="python3"
else
  echo "[错误] 需要 unzip 或 python3 解压源码包"
  exit 1
fi

fetch() {
  local url="$1" out="$2"
  if [[ "$DOWNLOADER" == "curl" ]]; then
    curl -fsSL --retry 2 --connect-timeout 15 "$url" -o "$out"
  else
    wget -q --tries=3 --timeout=20 "$url" -O "$out"
  fi
}

# ---------- 下载源码 ----------
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

URL="https://codeload.github.com/${REPO_SLUG}/zip/refs/heads/${BRANCH}"
echo "[3/5] 下载源码：$URL"
if [[ -n "$LOCAL_ZIP" ]]; then
  if [[ ! -f "$LOCAL_ZIP" ]]; then
    echo "[错误] 本地源码包不存在：$LOCAL_ZIP"
    exit 1
  fi
  cp "$LOCAL_ZIP" "$TMP/tarsk.zip"
  echo "      使用本地源码包：$LOCAL_ZIP"
elif ! fetch "$URL" "$TMP/tarsk.zip"; then
  echo "[错误] 下载失败。请检查网络，或改用代理："
  echo "       export https_proxy=http://127.0.0.1:7890 && curl ... | sudo -E bash -s -- ..."
  echo "       或使用 --local-zip /path/to/Tarsk-main.zip 离线安装"
  exit 1
fi

if [[ "$UNPACK" == "unzip" ]]; then
  unzip -q "$TMP/tarsk.zip" -d "$TMP/src"
else
  python3 -c 'import sys, zipfile; zipfile.ZipFile(sys.argv[1]).extractall(sys.argv[2])' "$TMP/tarsk.zip" "$TMP/src"
fi

SRC=$(find "$TMP/src" -maxdepth 1 -type d -name 'Tarsk-*' | head -n 1)
if [[ -z "$SRC" || ! -f "$SRC/public/index.php" ]]; then
  echo "[错误] 源码包结构异常，未找到 public/index.php"
  exit 1
fi
echo "[4/5] 源码解压完成：$SRC"

# ---------- 安装 / 升级 ----------
TS=$(date +%Y%m%d%H%M%S)
BAK=""
if [[ -d "$DEST" ]]; then
  if [[ $UPGRADE -eq 0 && ! -f "$DEST/config.php" ]]; then
    echo "[错误] 目标目录 $DEST 已存在但不是 Tarsk 安装，请用 --dir 指定其它目录"
    exit 1
  fi
  BAK="${DEST}.bak.${TS}"
  echo "[5/5] 备份现有目录到 $BAK"
  cp -a "$DEST" "$BAK"
  rm -rf "$DEST"
fi

mkdir -p "$DEST"
cp -a "$SRC/." "$DEST/"
echo "      已复制源码到 $DEST"

if [[ -n "$BAK" ]]; then
  for keep in config.php install.lock; do
    if [[ -f "$BAK/$keep" ]]; then
      cp -a "$BAK/$keep" "$DEST/$keep"
      echo "      已保留 $keep"
    fi
  done
  if [[ -d "$BAK/storage" ]]; then
    rm -rf "$DEST/storage"
    cp -a "$BAK/storage" "$DEST/storage"
    echo "      已保留 storage（日志/证书/备份/软件目录）"
  fi
fi

chmod +x "$DEST/install.sh" "$DEST/install-online.sh" 2>/dev/null || true
if [[ -d "$DEST/storage" ]]; then
  chmod -R 0755 "$DEST/storage" 2>/dev/null || true
fi

if [[ $UPGRADE -eq 1 || -f "$DEST/config.php" ]]; then
  if command -v systemctl >/dev/null 2>&1 && systemctl is-enabled tarsk >/dev/null 2>&1; then
    echo "      重启 systemd 服务 tarsk ..."
    systemctl restart tarsk || true
    systemctl --no-pager status tarsk | head -n 12 || true
  else
    echo "      未检测到 tarsk 服务，请手动重启面板进程"
  fi
  echo ""
  echo "升级完成：$DEST"
  echo "旧版本备份：${BAK:-无}"
  exit 0
fi

echo "      初始化数据库与管理员账号 ..."
bash "$DEST/install.sh" "${ARGS[@]+"${ARGS[@]}"}"

echo ""
echo "安装目录：$DEST"
