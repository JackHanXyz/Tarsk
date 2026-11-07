<?php
declare(strict_types=1);

/**
 * Tarsk FTP 服务核心库（RFC 959 常用子集）
 * 单进程 stream_select 事件循环，Windows 兼容（无 pcntl/posix 依赖）
 * 本文件只定义函数，不直接执行。
 */

/**
 * 启动 FTP 服务（阻塞循环）
 * @param array $options [
 *   'port' => int,              // 控制端口，默认 2121
 *   'host' => string,           // 监听地址，默认 0.0.0.0
 *   'pasv_min' => int, 'pasv_max' => int,  // 被动模式数据端口范围，默认 49000-49100
 *   'root' => string,           // 用户根目录基准（站点根目录）
 *   'log' => string,            // 日志文件路径
 *   'user_provider' => callable // function(string $username): ?array{password_hash:string,home_dir:string,status:string}
 * ]
 */
function ftpd_run(array $options): void
{
    $cfg = [
        'port' => (int)($options['port'] ?? 2121),
        'host' => (string)($options['host'] ?? '0.0.0.0'),
        'pasv_min' => (int)($options['pasv_min'] ?? 49000),
        'pasv_max' => (int)($options['pasv_max'] ?? 49100),
        'root' => rtrim(str_replace('\\', '/', (string)($options['root'] ?? '')), '/'),
        'log' => (string)($options['log'] ?? ''),
        'user_provider' => $options['user_provider'] ?? null,
    ];
    if ($cfg['port'] <= 0 || $cfg['port'] > 65535) {
        $cfg['port'] = 2121;
    }
    if ($cfg['pasv_min'] <= 0) {
        $cfg['pasv_min'] = 49000;
    }
    if ($cfg['pasv_max'] < $cfg['pasv_min']) {
        $cfg['pasv_max'] = $cfg['pasv_min'] + 100;
    }
    if (!is_callable($cfg['user_provider'])) {
        ftpd_log($cfg['log'], '-', '启动失败：缺少 user_provider 回调');
        fwrite(STDERR, "ftpd: 缺少 user_provider 回调\n");
        exit(1);
    }

    $errno = 0;
    $errstr = '';
    $server = @stream_socket_server(
        'tcp://' . $cfg['host'] . ':' . $cfg['port'],
        $errno,
        $errstr,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
    );
    if ($server === false) {
        ftpd_log($cfg['log'], '-', "启动失败 {$cfg['host']}:{$cfg['port']} ({$errstr})");
        fwrite(STDERR, "ftpd: 无法监听 {$cfg['host']}:{$cfg['port']} — {$errstr}\n");
        exit(1);
    }

    ftpd_log($cfg['log'], '-', "FTP 服务启动 {$cfg['host']}:{$cfg['port']} root={$cfg['root']}");
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, "ftpd: listening on {$cfg['host']}:{$cfg['port']}, root={$cfg['root']}\n");
    }

    $clients = []; // cid => ['sock','ip','buf','user','authed','home','cwd','type','rnfr','pasv','data','t']
    $serverId = (int)$server;

    while (true) {
        $read = [$server];
        $write = [];
        $except = null;
        $ctrlOf = [];
        $pasvOf = [];
        $dataOf = [];

        foreach ($clients as $cid => $c) {
            $read[] = $c['sock'];
            $ctrlOf[(int)$c['sock']] = $cid;
            if ($c['pasv'] !== null) {
                $read[] = $c['pasv'];
                $pasvOf[(int)$c['pasv']] = $cid;
            }
            if ($c['t'] !== null && $c['t']['sock'] !== null) {
                $dataOf[(int)$c['t']['sock']] = $cid;
                if ($c['t']['kind'] === 'stor') {
                    $read[] = $c['t']['sock'];
                } else {
                    $write[] = $c['t']['sock'];
                }
            }
        }

        $ready = @stream_select($read, $write, $except, null);
        if ($ready === false) {
            usleep(200000);
            continue;
        }

        foreach ($read as $sock) {
            $sid = (int)$sock;
            if ($sid === $serverId) {
                ftpd_accept_client($server, $clients);
                continue;
            }
            if (isset($pasvOf[$sid])) {
                $cid = $pasvOf[$sid];
                if (isset($clients[$cid])) {
                    ftpd_accept_data($clients[$cid]);
                }
                continue;
            }
            if (isset($dataOf[$sid])) {
                $cid = $dataOf[$sid];
                if (isset($clients[$cid])) {
                    ftpd_transfer_read($clients[$cid], $cfg);
                }
                continue;
            }
            if (isset($ctrlOf[$sid])) {
                $cid = $ctrlOf[$sid];
                if (isset($clients[$cid])) {
                    ftpd_ctrl_read($clients, $cid, $cfg);
                }
            }
        }

        foreach ($write as $sock) {
            $sid = (int)$sock;
            if (isset($dataOf[$sid])) {
                $cid = $dataOf[$sid];
                if (isset($clients[$cid])) {
                    ftpd_transfer_write($clients[$cid], $cfg);
                }
            }
        }
    }
}

/* ------------------------------- 日志 ------------------------------- */

function ftpd_log(string $file, string $ip, string $message): void
{
    if ($file === '') {
        return;
    }
    if (is_file($file)) {
        clearstatcache(true, $file);
        $size = @filesize($file);
        if ($size !== false && $size > 5 * 1024 * 1024) {
            $bak = $file . '.1';
            if (is_file($bak)) {
                @unlink($bak);
            }
            @rename($file, $bak);
        }
    }
    $line = sprintf("[%s] [%s] %s\r\n", date('Y-m-d H:i:s'), $ip, $message);
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

/* ------------------------------- 连接管理 ------------------------------- */

function ftpd_accept_client($server, array &$clients): void
{
    $conn = @stream_socket_accept($server, 0);
    if ($conn === false) {
        return;
    }
    $clients[(int)$conn] = [
        'sock' => $conn,
        'ip' => ftpd_peer_ip($conn) ?: '-',
        'buf' => '',
        'user' => '',
        'authed' => false,
        'home' => '',
        'cwd' => '/',
        'type' => 'A',
        'rnfr' => null,
        'pasv' => null,
        'data' => null,
        't' => null,
    ];
    ftpd_send($conn, "220 Tarsk FTP Server ready\r\n");
}

function ftpd_peer_ip($sock): string
{
    $name = (string)@stream_socket_get_name($sock, true);
    if ($name === '') {
        return '';
    }
    $pos = strrpos($name, ':');
    $ip = $pos === false ? $name : substr($name, 0, $pos);
    return trim($ip, '[]');
}

function ftpd_close_client(array &$c): void
{
    if ($c['t'] !== null) {
        if ($c['t']['fh'] !== null) {
            @fclose($c['t']['fh']);
        }
        if ($c['t']['sock'] !== null) {
            @fclose($c['t']['sock']);
        }
        $c['t'] = null;
    }
    ftpd_close_pasv($c);
    @fclose($c['sock']);
}

function ftpd_close_pasv(array &$c): void
{
    if ($c['pasv'] !== null) {
        @fclose($c['pasv']);
        $c['pasv'] = null;
    }
    if ($c['data'] !== null) {
        @fclose($c['data']);
        $c['data'] = null;
    }
}

/* ------------------------------- 数据连接 ------------------------------- */

function ftpd_accept_data(array &$c): void
{
    $conn = @stream_socket_accept($c['pasv'], 0);
    if ($conn === false) {
        return;
    }
    $peerIp = ftpd_peer_ip($conn);
    if ($peerIp !== $c['ip'] && $peerIp !== '127.0.0.1' && $c['ip'] !== '127.0.0.1') {
        @fclose($conn);
        return;
    }
    stream_set_blocking($conn, false);
    @fclose($c['pasv']);
    $c['pasv'] = null;
    if ($c['t'] !== null && $c['t']['sock'] === null) {
        $c['t']['sock'] = $conn;
        return;
    }
    if ($c['data'] !== null) {
        @fclose($c['data']);
    }
    $c['data'] = $conn;
}

/**
 * 发起一次数据传输（LIST/NLST/RETR/STOR 共用）
 */
function ftpd_start_transfer(array &$c, array $t): bool
{
    if ($c['t'] !== null) {
        if ($c['t']['fh'] !== null) {
            @fclose($c['t']['fh']);
        }
        if ($c['t']['sock'] !== null) {
            @fclose($c['t']['sock']);
        }
        $c['t'] = null;
    }
    if ($c['data'] === null && $c['pasv'] === null) {
        ftpd_reply($c, 425, 'Use PASV first');
        return false;
    }
    if ($c['data'] !== null) {
        $t['sock'] = $c['data'];
        $c['data'] = null;
        if ($c['pasv'] !== null) {
            @fclose($c['pasv']);
            $c['pasv'] = null;
        }
    }
    $c['t'] = $t;
    ftpd_reply($c, 150, 'Opening data connection');
    return true;
}

/**
 * STOR：数据连接可读，写入本地文件
 */
function ftpd_transfer_read(array &$c, array $cfg): void
{
    $t = &$c['t'];
    if ($t === null || $t['sock'] === null || $t['kind'] !== 'stor') {
        return;
    }
    $sock = $t['sock'];
    $chunk = @fread($sock, 65536);
    if ($chunk !== false && $chunk !== '') {
        if ($t['fh'] !== null) {
            @fwrite($t['fh'], $chunk);
        }
        $t['size'] += strlen($chunk);
        return;
    }
    if ($chunk === '' && !feof($sock)) {
        return; // 暂时无数据
    }
    if ($t['fh'] !== null) {
        @fclose($t['fh']);
    }
    @fclose($sock);
    ftpd_log($cfg['log'], $c['ip'], "上传 {$t['vpath']} size={$t['size']}");
    $c['t'] = null;
    ftpd_reply($c, 226, 'Transfer complete');
}

/**
 * RETR/LIST：数据连接可写，发送文件或列表内容
 */
function ftpd_transfer_write(array &$c, array $cfg): void
{
    $t = &$c['t'];
    if ($t === null || $t['sock'] === null || $t['kind'] === 'stor') {
        return;
    }
    $sock = $t['sock'];

    if ($t['out'] === '' && !$t['eof'] && $t['fh'] !== null) {
        $chunk = @fread($t['fh'], 65536);
        if ($chunk === false || $chunk === '') {
            $t['eof'] = true;
            @fclose($t['fh']);
            $t['fh'] = null;
        } else {
            $t['out'] = $chunk;
        }
    }

    if ($t['out'] !== '') {
        $n = @fwrite($sock, $t['out']);
        if ($n === false || ($n === 0 && feof($sock))) {
            if ($t['fh'] !== null) {
                @fclose($t['fh']);
            }
            @fclose($sock);
            $c['t'] = null;
            ftpd_reply($c, 426, 'Connection closed; transfer aborted');
            return;
        }
        if ($n > 0) {
            $t['size'] += $n;
            $t['out'] = substr($t['out'], $n);
        }
    }

    if ($t['eof'] && $t['out'] === '') {
        @fclose($sock);
        if ($t['kind'] === 'retr') {
            ftpd_log($cfg['log'], $c['ip'], "下载 {$t['vpath']} size={$t['size']}");
        }
        $c['t'] = null;
        ftpd_reply($c, 226, 'Transfer complete');
    }
}

/* ------------------------------- 控制通道 ------------------------------- */

function ftpd_ctrl_read(array &$clients, int $cid, array $cfg): void
{
    $c = &$clients[$cid];
    $chunk = @fread($c['sock'], 8192);
    if ($chunk === false || ($chunk === '' && feof($c['sock']))) {
        ftpd_close_client($c);
        unset($clients[$cid]);
        return;
    }
    $c['buf'] .= $chunk;
    if (strlen($c['buf']) > 65536) {
        ftpd_close_client($c);
        unset($clients[$cid]);
        return;
    }
    while (($pos = strpos($c['buf'], "\n")) !== false) {
        $line = rtrim(substr($c['buf'], 0, $pos), "\r");
        $c['buf'] = substr($c['buf'], $pos + 1);
        if (!ftpd_command($c, $line, $cfg)) {
            ftpd_close_client($c);
            unset($clients[$cid]);
            return;
        }
    }
}

function ftpd_send($sock, string $data): bool
{
    $len = strlen($data);
    $off = 0;
    $guard = 0;
    while ($off < $len) {
        $n = @fwrite($sock, substr($data, $off));
        if ($n === false) {
            return false;
        }
        if ($n === 0) {
            if (feof($sock) || ++$guard > 1000) {
                return false;
            }
            usleep(1000);
            continue;
        }
        $off += $n;
    }
    return true;
}

function ftpd_reply(array &$c, int $code, string $msg): void
{
    ftpd_send($c['sock'], sprintf("%d %s\r\n", $code, $msg));
}

/* ------------------------------- 路径沙箱 ------------------------------- */

/**
 * 归一化 FTP 虚拟路径：.. 在根处截断，绝不逃逸到 / 之上
 */
function ftpd_vnorm(string $cwd, string $arg): string
{
    $arg = str_replace('\\', '/', trim($arg));
    $parts = [];
    if ($arg === '' || $arg[0] !== '/') {
        foreach (explode('/', $cwd) as $p) {
            if ($p !== '') {
                $parts[] = $p;
            }
        }
    }
    foreach (explode('/', $arg) as $p) {
        if ($p === '' || $p === '.') {
            continue;
        }
        if ($p === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $p;
    }
    return '/' . implode('/', $parts);
}

/**
 * 虚拟路径转真实路径；存在符号链接逃逸或父目录越界时返回 null
 */
function ftpd_real(array $c, string $v): ?string
{
    $real = $c['home'] . ($v === '/' ? '' : $v);
    if (file_exists($real)) {
        $rp = realpath($real);
        if ($rp === false) {
            return null;
        }
        $rp = str_replace('\\', '/', $rp);
        return ftpd_within($c['home'], $rp) ? $rp : null;
    }
    $parent = realpath(dirname($real));
    if ($parent === false) {
        return null;
    }
    $parent = str_replace('\\', '/', $parent);
    return ftpd_within($c['home'], $parent) ? $real : null;
}

function ftpd_within(string $home, string $path): bool
{
    $home = rtrim(str_replace('\\', '/', $home), '/');
    $path = rtrim(str_replace('\\', '/', $path), '/');
    return strcasecmp($path, $home) === 0 || stripos($path, $home . '/') === 0;
}

/* ------------------------------- 命令分发 ------------------------------- */

/**
 * 处理一行 FTP 命令；返回 false 表示关闭控制连接
 */
function ftpd_command(array &$c, string $line, array $cfg): bool
{
    if ($line === '') {
        return true;
    }
    $parts = preg_split('/\s+/', $line, 2);
    $cmd = strtoupper($parts[0]);
    $arg = isset($parts[1]) ? trim($parts[1]) : '';

    switch ($cmd) {
        case 'USER':
            $c['user'] = $arg;
            $c['authed'] = false;
            ftpd_reply($c, 331, "Password required for {$arg}");
            return true;
        case 'PASS':
            ftpd_cmd_pass($c, $arg, $cfg);
            return true;
        case 'QUIT':
            ftpd_reply($c, 221, 'Goodbye');
            return false;
        case 'SYST':
            ftpd_reply($c, 215, 'UNIX Type: L8');
            return true;
        case 'FEAT':
            ftpd_send($c['sock'], "211-Features:\r\n UTF8\r\n SIZE\r\n MDTM\r\n EPSV\r\n PASV\r\n211 End\r\n");
            return true;
        case 'OPTS':
            ftpd_reply($c, 200, 'OK');
            return true;
        case 'NOOP':
            ftpd_reply($c, 200, 'OK');
            return true;
        case 'TYPE':
            $t = strtoupper($arg);
            if ($t === 'I' || $t === 'A' || $t === 'L 8' || $t === 'A N') {
                $c['type'] = ($t[0] === 'I' || $t[0] === 'L') ? 'I' : 'A';
                ftpd_reply($c, 200, 'Type set to ' . $c['type']);
            } else {
                ftpd_reply($c, 504, 'Unsupported type');
            }
            return true;
    }

    if (!$c['authed']) {
        ftpd_reply($c, 530, 'Please login with USER and PASS');
        return true;
    }

    switch ($cmd) {
        case 'PWD':
        case 'XPWD':
            ftpd_reply($c, 257, '"' . $c['cwd'] . '" is the current directory');
            break;
        case 'CWD':
            ftpd_cmd_cwd($c, $arg === '' ? '/' : $arg);
            break;
        case 'CDUP':
            ftpd_cmd_cwd($c, '..');
            break;
        case 'PASV':
            ftpd_cmd_pasv($c, $cfg, false);
            break;
        case 'EPSV':
            ftpd_cmd_pasv($c, $cfg, true);
            break;
        case 'LIST':
        case 'NLST':
            ftpd_cmd_list($c, $arg, $cmd === 'NLST');
            break;
        case 'RETR':
            ftpd_cmd_retr($c, $arg);
            break;
        case 'STOR':
            ftpd_cmd_stor($c, $arg);
            break;
        case 'DELE':
            ftpd_cmd_dele($c, $arg, $cfg);
            break;
        case 'MKD':
        case 'XMKD':
            ftpd_cmd_mkd($c, $arg, $cfg);
            break;
        case 'RMD':
        case 'XRMD':
            ftpd_cmd_rmd($c, $arg);
            break;
        case 'RNFR':
            ftpd_cmd_rnfr($c, $arg);
            break;
        case 'RNTO':
            ftpd_cmd_rnto($c, $arg);
            break;
        case 'SIZE':
            ftpd_cmd_size($c, $arg);
            break;
        case 'MDTM':
            ftpd_cmd_mdtm($c, $arg);
            break;
        default:
            ftpd_reply($c, 502, 'Command not implemented');
            break;
    }
    return true;
}

function ftpd_cmd_pass(array &$c, string $pass, array $cfg): void
{
    $user = $c['user'];
    if ($user === '') {
        ftpd_reply($c, 503, 'Login with USER first');
        return;
    }
    $rec = null;
    try {
        $rec = call_user_func($cfg['user_provider'], $user);
    } catch (Throwable $e) {
        $rec = null;
    }
    $ok = is_array($rec)
        && ($rec['status'] ?? '') === 'enabled'
        && ($rec['password_hash'] ?? '') !== ''
        && password_verify($pass, (string)$rec['password_hash']);
    if (!$ok) {
        ftpd_log($cfg['log'], $c['ip'], "登录失败 user={$user}");
        ftpd_reply($c, 530, 'Login incorrect');
        return;
    }
    $homeDir = trim(str_replace('\\', '/', (string)($rec['home_dir'] ?? '')), '/');
    $home = $cfg['root'] . ($homeDir !== '' ? '/' . $homeDir : '');
    if (!is_dir($home)) {
        @mkdir($home, 0777, true);
    }
    $rp = realpath($home);
    if ($rp === false || !is_dir($rp)) {
        ftpd_log($cfg['log'], $c['ip'], "登录失败 user={$user} 主目录不可用");
        ftpd_reply($c, 530, 'Login incorrect');
        return;
    }
    $c['home'] = rtrim(str_replace('\\', '/', $rp), '/');
    $c['cwd'] = '/';
    $c['authed'] = true;
    ftpd_log($cfg['log'], $c['ip'], "登录成功 user={$user}");
    ftpd_reply($c, 230, "User {$user} logged in");
}

function ftpd_cmd_cwd(array &$c, string $arg): void
{
    $v = ftpd_vnorm($c['cwd'], $arg);
    $real = ftpd_real($c, $v);
    if ($real === null || !is_dir($real)) {
        ftpd_reply($c, 550, 'Failed to change directory');
        return;
    }
    $c['cwd'] = $v;
    ftpd_reply($c, 250, "Directory changed to {$v}");
}

function ftpd_cmd_pasv(array &$c, array $cfg, bool $epsv): void
{
    ftpd_close_pasv($c);
    $port = null;
    $sock = null;
    for ($i = 0; $i < 30; $i++) {
        $try = random_int($cfg['pasv_min'], $cfg['pasv_max']);
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_server(
            'tcp://' . $cfg['host'] . ':' . $try,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );
        if ($sock !== false) {
            $port = $try;
            break;
        }
    }
    if ($port === null || $sock === false) {
        ftpd_reply($c, 425, 'Cannot open passive port');
        return;
    }
    $c['pasv'] = $sock;
    if ($epsv) {
        ftpd_reply($c, 229, "Entering Extended Passive Mode (|||{$port}|)");
        return;
    }
    $ip = $cfg['host'];
    if ($ip === '0.0.0.0' || $ip === '::' || $ip === '') {
        $ip = (string)@gethostbyname((string)gethostname());
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = '127.0.0.1';
        }
    }
    $octets = explode('.', $ip);
    if (count($octets) !== 4) {
        $octets = ['127', '0', '0', '1'];
    }
    ftpd_reply($c, 227, sprintf(
        'Entering Passive Mode (%s,%d,%d)',
        implode(',', $octets),
        intdiv($port, 256),
        $port % 256
    ));
}

function ftpd_cmd_list(array &$c, string $arg, bool $namesOnly): void
{
    // 剥离 -al / -l 等选项参数
    $arg = trim($arg);
    while ($arg !== '' && $arg[0] === '-') {
        $pos = strpos($arg, ' ');
        if ($pos === false) {
            $arg = '';
            break;
        }
        $arg = trim(substr($arg, $pos + 1));
    }
    $v = ftpd_vnorm($c['cwd'], $arg === '' ? '.' : $arg);
    $real = ftpd_real($c, $v);
    if ($real === null || (!is_dir($real) && !is_file($real))) {
        ftpd_reply($c, 550, 'No such file or directory');
        return;
    }
    $lines = [];
    if (is_file($real)) {
        $lines[] = $namesOnly ? basename($real) : ftpd_list_line($real, basename($real));
    } else {
        $items = @scandir($real);
        if ($items === false) {
            ftpd_reply($c, 550, 'Cannot open directory');
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $lines[] = $namesOnly ? $item : ftpd_list_line($real . '/' . $item, $item);
        }
    }
    ftpd_start_transfer($c, [
        'kind' => 'list',
        'vpath' => $v,
        'sock' => null,
        'fh' => null,
        'out' => $lines === [] ? '' : implode("\r\n", $lines) . "\r\n",
        'eof' => true,
        'size' => 0,
    ]);
}

function ftpd_list_line(string $path, string $name): string
{
    $isDir = is_dir($path);
    $perms = $isDir ? 'drwxr-xr-x' : '-rw-r--r--';
    $size = $isDir ? 0 : (int)@filesize($path);
    $mtime = @filemtime($path);
    if ($mtime === false) {
        $mtime = time();
    }
    return sprintf('%s 1 owner group %d %s %s', $perms, $size, date('M d H:i', $mtime), $name);
}

function ftpd_cmd_retr(array &$c, string $arg): void
{
    $v = ftpd_vnorm($c['cwd'], $arg);
    $real = ftpd_real($c, $v);
    if ($real === null || !is_file($real)) {
        ftpd_reply($c, 550, 'File not found');
        return;
    }
    $fh = @fopen($real, 'rb');
    if ($fh === false) {
        ftpd_reply($c, 550, 'Cannot open file');
        return;
    }
    ftpd_start_transfer($c, [
        'kind' => 'retr',
        'vpath' => $v,
        'sock' => null,
        'fh' => $fh,
        'out' => '',
        'eof' => false,
        'size' => 0,
    ]);
}

function ftpd_cmd_stor(array &$c, string $arg): void
{
    $v = ftpd_vnorm($c['cwd'], $arg);
    $real = ftpd_real($c, $v);
    if ($real === null || is_dir($real)) {
        ftpd_reply($c, 550, 'Cannot create file');
        return;
    }
    $fh = @fopen($real, 'wb');
    if ($fh === false) {
        ftpd_reply($c, 550, 'Cannot create file');
        return;
    }
    ftpd_start_transfer($c, [
        'kind' => 'stor',
        'vpath' => $v,
        'sock' => null,
        'fh' => $fh,
        'out' => '',
        'eof' => false,
        'size' => 0,
    ]);
}

function ftpd_cmd_dele(array &$c, string $arg, array $cfg): void
{
    $v = ftpd_vnorm($c['cwd'], $arg);
    $real = ftpd_real($c, $v);
    if ($real === null || !is_file($real) || !@unlink($real)) {
        ftpd_reply($c, 550, 'Delete failed');
        return;
    }
    ftpd_log($cfg['log'], $c['ip'], "删除 {$v}");
    ftpd_reply($c, 250, 'File deleted');
}

function ftpd_cmd_mkd(array &$c, string $arg, array $cfg): void
{
    $v = ftpd_vnorm($c['cwd'], $arg);
    $real = ftpd_real($c, $v);
    if ($real === null || file_exists($real) || !@mkdir($real)) {
        ftpd_reply($c, 550, 'Create directory failed');
        return;
    }
    ftpd_log($cfg['log'], $c['ip'], "建目录 {$v}");
    ftpd_reply($c, 257, '"' . $v . '" directory created');
}

function ftpd_cmd_rmd(array &$c, string $arg): void
{
    $v = ftpd_vnorm($c['cwd'], $arg);
    if ($v === '/') {
        ftpd_reply($c, 550, 'Cannot remove root directory');
        return;
    }
    $real = ftpd_real($c, $v);
    if ($real === null || !is_dir($real) || !@rmdir($real)) {
        ftpd_reply($c, 550, 'Remove directory failed');
        return;
    }
    ftpd_reply($c, 250, 'Directory removed');
}

function ftpd_cmd_rnfr(array &$c, string $arg): void
{
    $v = ftpd_vnorm($c['cwd'], $arg);
    $real = ftpd_real($c, $v);
    if ($real === null || !file_exists($real)) {
        ftpd_reply($c, 550, 'No such file or directory');
        return;
    }
    $c['rnfr'] = $real;
    ftpd_reply($c, 350, 'File exists, ready for destination');
}

function ftpd_cmd_rnto(array &$c, string $arg): void
{
    if ($c['rnfr'] === null) {
        ftpd_reply($c, 503, 'RNFR required first');
        return;
    }
    $v = ftpd_vnorm($c['cwd'], $arg);
    $real = ftpd_real($c, $v);
    $from = $c['rnfr'];
    $c['rnfr'] = null;
    if ($real === null || !@rename($from, $real)) {
        ftpd_reply($c, 550, 'Rename failed');
        return;
    }
    ftpd_reply($c, 250, 'Rename successful');
}

function ftpd_cmd_size(array &$c, string $arg): void
{
    $v = ftpd_vnorm($c['cwd'], $arg);
    $real = ftpd_real($c, $v);
    if ($real === null || !is_file($real)) {
        ftpd_reply($c, 550, 'No such file');
        return;
    }
    ftpd_reply($c, 213, (string)(int)@filesize($real));
}

function ftpd_cmd_mdtm(array &$c, string $arg): void
{
    $v = ftpd_vnorm($c['cwd'], $arg);
    $real = ftpd_real($c, $v);
    if ($real === null || !file_exists($real)) {
        ftpd_reply($c, 550, 'No such file');
        return;
    }
    $mtime = @filemtime($real);
    if ($mtime === false) {
        ftpd_reply($c, 550, 'No such file');
        return;
    }
    ftpd_reply($c, 213, gmdate('YmdHis', $mtime));
}
