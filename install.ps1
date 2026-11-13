#Requires -Version 5.1
<#
.SYNOPSIS
  Tarsk 面板一键安装（Windows）

.DESCRIPTION
  检查 PHP 环境、写入配置、初始化数据库与管理员账号，
  注册登录自启计划任务并启动面板。

.EXAMPLE
  .\install.ps1 -DbUser root -DbPass "yourpass" -AdminPass "YourPassword123"
#>
param(
    [string]$DbHost = '127.0.0.1',
    [int]$DbPort = 3306,
    [string]$DbName = 'tarsk',
    [string]$DbUser = 'root',
    [string]$DbPass = '',
    [string]$AdminUser = 'admin',
    [string]$AdminPass = '',
    [int]$PanelPort = 8888,
    [switch]$NoTask,
    [switch]$Force
)

$ErrorActionPreference = 'Stop'
$Root = $PSScriptRoot
$Separator = ('=' * 58)

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

Write-Host $Separator
Write-Host ' Tarsk 面板一键安装'
Write-Host $Separator

$php = Find-Php
if (-not $php) {
    Write-Host '[错误] 未找到 PHP。请先安装 PHP 8.1+：' -ForegroundColor Red
    Write-Host '       winget install PHP.PHP.8.3' -ForegroundColor Yellow
    Write-Host '       安装后重新运行本脚本。'
    exit 1
}
Write-Host "[1/5] PHP: $php"

$version = & $php -r "echo PHP_VERSION;" 2>$null
if ([version](($version -replace '[^0-9.].*$', '')) -lt [version]'8.1.0') {
    Write-Host "[错误] PHP 版本过低（当前 $version），需要 8.1 及以上。" -ForegroundColor Red
    exit 1
}

$modules = (& $php -m) -join ' '
foreach ($ext in @('pdo_mysql', 'openssl', 'mbstring')) {
    if ($modules -notmatch "\b$ext\b") {
        Write-Host "[错误] 缺少 PHP 扩展 $ext，请在 php.ini 中启用后重试。" -ForegroundColor Red
        exit 1
    }
}
Write-Host "[2/5] PHP 版本 $version，扩展检查通过"

# 端口占用检测：8888 常被其他面板占用，自动改用空闲端口，避免安装完成后打开错误的页面
function Test-PortListening([int]$Port) {
    try {
        return [bool](Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue)
    } catch {
        return [bool](netstat -ano | Select-String ":$Port\s" | Select-String 'LISTENING')
    }
}

function Test-TarskOnPort([int]$Port) {
    try {
        $resp = Invoke-WebRequest "http://127.0.0.1:$Port/login" -UseBasicParsing -TimeoutSec 3
        return ($resp.Content -match 'Tarsk')
    } catch {
        return $false
    }
}

$reusePanel = $false
if (Test-PortListening $PanelPort) {
    if (Test-TarskOnPort $PanelPort) {
        $reusePanel = $true
        Write-Host "[提示] 检测到 Tarsk 已在端口 $PanelPort 运行，继续使用该端口。" -ForegroundColor Yellow
    } else {
        $freePort = 8890..8920 | Where-Object { -not (Test-PortListening $_) } | Select-Object -First 1
        if (-not $freePort) {
            Write-Host "[错误] 端口 $PanelPort 已被其他程序占用，且 8890-8920 无空闲端口，请用 -PanelPort 指定。" -ForegroundColor Red
            exit 1
        }
        Write-Host "[提示] 端口 $PanelPort 已被其他程序占用，自动改用 $freePort。" -ForegroundColor Yellow
        $PanelPort = [int]$freePort
    }
}

if ([string]::IsNullOrWhiteSpace($AdminPass)) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'
    $AdminPass = -join (1..12 | ForEach-Object { $chars[(Get-Random -Maximum $chars.Length)] })
}

if ([string]::IsNullOrWhiteSpace($DbPass)) {
    Write-Host '[提示] 未提供数据库密码，将使用空密码连接。若连接失败请重新运行并传入 -DbPass。' -ForegroundColor Yellow
}

$cliArgs = @(
    (Join-Path $Root 'bin\configure.php'),
    "--host=$DbHost",
    "--port=$DbPort",
    "--name=$DbName",
    "--user=$DbUser",
    "--pass=$DbPass",
    "--admin=$AdminUser",
    "--admin-pass=$AdminPass",
    "--panel-port=$PanelPort"
)
if ($Force) { $cliArgs += '--force' }

Write-Host "[3/5] 写入配置并初始化数据库…"
& $php @cliArgs
if ($LASTEXITCODE -ne 0) {
    Write-Host '[错误] 初始化失败，请检查上面的输出。' -ForegroundColor Red
    exit 1
}

if (-not $NoTask) {
    Write-Host '[4/5] 注册登录自启任务 Tarsk_Panel…'
    $taskCommand = 'cmd /c cd /d "' + $Root + '" && "' + $php + '" -S 127.0.0.1:' + $PanelPort + ' -t public public/router.php'
    schtasks /Create /TN "Tarsk_Panel" /TR $taskCommand /SC ONLOGON /RL LIMITED /F | Out-Null
    if ($LASTEXITCODE -eq 0) {
        Write-Host '      已注册。可在任务计划程序中删除 Tarsk_Panel 取消自启。'
    } else {
        Write-Host '      注册失败（可能需要管理员权限），可稍后手动运行面板。' -ForegroundColor Yellow
    }
} else {
    Write-Host '[4/5] 已跳过自启任务注册'
}

Write-Host '[5/5] 启动面板…'
$logDir = Join-Path $Root 'storage\logs'
if (-not (Test-Path $logDir)) { New-Item -ItemType Directory -Path $logDir -Force | Out-Null }

if ($reusePanel) {
    Write-Host "      Tarsk 已在端口 $PanelPort 运行，跳过启动。"
} else {
    # 不用 Start-Process 的输出重定向：目标日志被占用时会永久阻塞，改用 cmd 追加写入
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $panelLog = Join-Path $logDir "panel-$stamp.log"
    $cmdLine = '"' + $php + '" -S 127.0.0.1:' + $PanelPort + ' -t public public/router.php >> "' + $panelLog + '" 2>&1'
    Start-Process -FilePath 'cmd.exe' -ArgumentList '/c', $cmdLine -WorkingDirectory $Root -WindowStyle Hidden
    Start-Sleep -Milliseconds 1500
    if (-not (Test-TarskOnPort $PanelPort)) {
        Write-Host "      [警告] 面板未响应，请查看日志：$panelLog" -ForegroundColor Yellow
    }
}

Write-Host ''
Write-Host $Separator
Write-Host ' 安装完成'
Write-Host $Separator
Write-Host " 面板地址 : http://127.0.0.1:$PanelPort"
Write-Host " 管理员   : $AdminUser"
Write-Host " 管理员密码: $AdminPass"
Write-Host " 数据库   : $DbName @ ${DbHost}:$DbPort"
Write-Host ''
Write-Host ' 下一步：登录后进入「软件商店」，一键安装 Nginx / PHP / Redis。'
Write-Host $Separator

Start-Process "http://127.0.0.1:$PanelPort"
