#Requires -Version 5.1
<#
.SYNOPSIS
  Tarsk 面板云端安装 / 升级（Windows）

.DESCRIPTION
  从 GitHub 下载最新源码包，解压到指定目录，保留 config.php 与 storage，
  首次安装时自动调用 install.ps1 完成初始化与开机自启注册。

.EXAMPLE
  # 首次安装
  .\install-online.ps1 -Dir 'C:\Tarsk' -DbUser root -DbPass 'dbpass' -AdminPass 'YourPassword'

.EXAMPLE
  # 升级到最新代码（保留配置与数据）
  .\install-online.ps1 -Dir 'C:\Tarsk' -Upgrade

.EXAMPLE
  # 走本地代理下载
  .\install-online.ps1 -Dir 'C:\Tarsk' -Proxy 'http://127.0.0.1:10808' -AdminPass 'YourPassword'
#>
param(
    [string]$Dir = 'C:\Tarsk',
    [string]$Branch = 'main',
    [switch]$Upgrade,
    [string]$DbHost = '127.0.0.1',
    [int]$DbPort = 3306,
    [string]$DbName = 'tarsk',
    [string]$DbUser = 'root',
    [string]$DbPass = '',
    [string]$AdminUser = 'admin',
    [string]$AdminPass = '',
    [int]$PanelPort = 8888,
    [string]$Proxy = '',
    [string]$LocalZip = '',
    [switch]$NoTask,
    [switch]$Force
)

$ErrorActionPreference = 'Stop'
try {
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
} catch { }

$Separator = ('=' * 58)
Write-Host $Separator
Write-Host ' Tarsk 面板云端安装'
Write-Host $Separator

# ---------- 依赖检测 ----------
function Find-Php {
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    $candidates = @()
    foreach ($pattern in @('D:\BtSoft\php\*\php.exe', 'C:\php\php.exe', 'C:\php\*\php.exe')) {
        $candidates += Get-ChildItem $pattern -ErrorAction SilentlyContinue | ForEach-Object { $_.FullName }
    }
    if ($candidates.Count -gt 0) { return $candidates[-1] }
    return $null
}

$php = Find-Php
if (-not $php) {
    Write-Host '[错误] 未找到 PHP。请先安装 PHP 8.1+：' -ForegroundColor Red
    Write-Host '       winget install PHP.PHP.8.3' -ForegroundColor Yellow
    exit 1
}
$version = & $php -r "echo PHP_VERSION;" 2>$null
Write-Host "[1/5] PHP $version ($php)"
if ([version](($version -replace '[^0-9.].*$', '')) -lt [version]'8.1.0') {
    Write-Host "[错误] PHP 版本过低（$version），需要 8.1 及以上。" -ForegroundColor Red
    exit 1
}

$modules = (& $php -m) -join ' '
$missing = @()
foreach ($ext in @('pdo_mysql', 'openssl', 'mbstring')) {
    if ($modules -notmatch "\b$ext\b") { $missing += $ext }
}
if ($missing.Count -gt 0) {
    Write-Host ("[错误] 缺少 PHP 扩展：" + ($missing -join ', ')) -ForegroundColor Red
    Write-Host '       请在 php.ini 中启用 extension=pdo_mysql、openssl、mbstring 后重试。'
    exit 1
}
Write-Host '[2/5] PHP 扩展检查通过'

# ---------- 下载源码 ----------
$url = "https://codeload.github.com/JackHanXyz/Tarsk/zip/refs/heads/$Branch"
$tmp = Join-Path $env:TEMP ("tarsk-online-" + (Get-Random))
New-Item -ItemType Directory -Path $tmp -Force | Out-Null
$zip = Join-Path $tmp 'tarsk.zip'

Write-Host "[3/5] 下载源码：$url"
if ($LocalZip -ne '') {
    if (-not (Test-Path $LocalZip)) {
        Write-Host "[错误] 本地源码包不存在：$LocalZip" -ForegroundColor Red
        Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
        exit 1
    }
    Copy-Item $LocalZip $zip -Force
    Write-Host "      使用本地源码包：$LocalZip"
} else {
    try {
        $req = @{ Uri = $url; OutFile = $zip; UseBasicParsing = $true }
        if ($Proxy -ne '') { $req.Proxy = $Proxy }
        Invoke-WebRequest @req
    } catch {
        Write-Host "[错误] 下载失败：$($_.Exception.Message)" -ForegroundColor Red
        Write-Host '       若网络受限，可指定代理：-Proxy "http://127.0.0.1:10808"，或用 -LocalZip 指定离线包' -ForegroundColor Yellow
        Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
        exit 1
    }
}

Write-Host '[4/5] 解压源码...'
Expand-Archive -Path $zip -DestinationPath (Join-Path $tmp 'src') -Force
$src = Join-Path (Join-Path $tmp 'src') "Tarsk-$Branch"
if (-not (Test-Path (Join-Path $src 'public\index.php'))) {
    $found = Get-ChildItem (Join-Path $tmp 'src') -Directory | Select-Object -First 1
    if ($found) { $src = $found.FullName }
}
if (-not (Test-Path (Join-Path $src 'public\index.php'))) {
    Write-Host '[错误] 源码包结构异常，未找到 public\index.php' -ForegroundColor Red
    Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
    exit 1
}

# ---------- 安装 / 升级 ----------
$keep = Join-Path $tmp 'keep'
$hasExisting = Test-Path $Dir
$hasConfig = Test-Path (Join-Path $Dir 'config.php')

if ($hasExisting -and -not $hasConfig -and -not $Upgrade) {
    Write-Host "[错误] 目标目录 $Dir 已存在但不是 Tarsk 安装，请用 -Dir 指定其它目录。" -ForegroundColor Red
    Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
    exit 1
}

$backup = ''
if ($hasExisting) {
    $stamp = Get-Date -Format 'yyyyMMddHHmmss'
    $backup = "$Dir.bak.$stamp"
    Write-Host "[5/5] 备份现有目录到 $backup"
    Copy-Item $Dir $backup -Recurse -Force

    New-Item -ItemType Directory -Path $keep -Force | Out-Null
    foreach ($item in @('config.php', 'install.lock', 'storage')) {
        $path = Join-Path $Dir $item
        if (Test-Path $path) {
            Copy-Item $path $keep -Recurse -Force
            Write-Host "      已保留 $item"
        }
    }
    Remove-Item $Dir -Recurse -Force
}

Write-Host "      复制源码到 $Dir"
Copy-Item $src $Dir -Recurse -Force

if (Test-Path $keep) {
    Get-ChildItem $keep | ForEach-Object {
        Copy-Item $_.FullName $Dir -Recurse -Force
    }
    Write-Host '      已恢复配置与 storage 数据'
}

if ($Upgrade -or $hasConfig) {
    $task = Get-ScheduledTask -TaskName 'Tarsk_Panel' -ErrorAction SilentlyContinue
    if ($task) {
        Write-Host '      重启计划任务 Tarsk_Panel ...'
        schtasks /End /TN Tarsk_Panel 2>$null | Out-Null
        Start-Sleep -Seconds 1
        schtasks /Run /TN Tarsk_Panel 2>$null | Out-Null
    } else {
        Write-Host '      未检测到 Tarsk_Panel 计划任务，请手动重启面板进程。' -ForegroundColor Yellow
    }
    Write-Host ''
    Write-Host "升级完成：$Dir" -ForegroundColor Green
    if ($backup -ne '') { Write-Host "旧版本备份：$backup" }
} else {
    Write-Host '      初始化数据库与管理员账号 ...'
    $installArgs = @{
        DbHost = $DbHost; DbPort = $DbPort; DbName = $DbName
        DbUser = $DbUser; DbPass = $DbPass
        AdminUser = $AdminUser; AdminPass = $AdminPass
        PanelPort = $PanelPort
    }
    if ($NoTask) { $installArgs.NoTask = $true }
    if ($Force) { $installArgs.Force = $true }
    & (Join-Path $Dir 'install.ps1') @installArgs
}

Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
Write-Host ''
Write-Host "安装目录：$Dir"
