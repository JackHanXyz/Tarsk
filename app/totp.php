<?php
declare(strict_types=1);

/**
 * TOTP（RFC 6238）两步验证，纯 PHP 实现，兼容 Google Authenticator / 微软 Authenticator
 */

function totp_base32_encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($data) as $char) {
        $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $out .= $alphabet[bindec($chunk)];
    }
    return $out;
}

function totp_base32_decode(string $input): string
{
    $alphabet = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
    $input = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $input));
    $bits = '';
    foreach (str_split($input) as $char) {
        if (!isset($alphabet[$char])) {
            continue;
        }
        $bits .= str_pad(decbin($alphabet[$char]), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $out .= chr(bindec($chunk));
        }
    }
    return $out;
}

function totp_generate_secret(int $bytes = 20): string
{
    return totp_base32_encode(random_bytes($bytes));
}

/**
 * 计算某一时间片的动态码
 */
function totp_code(string $secret, int $timestamp = 0, int $period = 30, int $digits = 6): string
{
    $timestamp = $timestamp ?: time();
    $counter = intdiv($timestamp, $period);
    $binCounter = pack('N*', 0, $counter);
    $key = totp_base32_decode($secret);
    if ($key === '') {
        return '';
    }
    $hash = hash_hmac('sha1', $binCounter, $key, true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $truncated = (
        ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF)
    );
    $code = $truncated % (10 ** $digits);
    return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
}

/**
 * 校验动态码，允许前后各 $window 个时间片
 */
function totp_verify(string $secret, string $code, int $window = 1): bool
{
    $code = preg_replace('/\s+/', '', $code);
    if ($code === '' || !ctype_digit($code)) {
        return false;
    }
    for ($i = -$window; $i <= $window; $i++) {
        $expected = totp_code($secret, time() + $i * 30);
        if ($expected !== '' && hash_equals($expected, $code)) {
            return true;
        }
    }
    return false;
}

/**
 * 生成 otpauth:// 链接，供验证器扫码/手动录入
 */
function totp_otpauth_url(string $secret, string $account, string $issuer): string
{
    return 'otpauth://totp/'
        . rawurlencode($issuer) . ':' . rawurlencode($account)
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

/* ----------------------------- 一次性恢复码 ----------------------------- */

/**
 * 恢复码：手机丢失时用来登录的一次性口令。
 * 10 个码，每个 10 位十六进制（5-5 分组显示），库里只存 password_hash。
 *
 * @return array{plain: string[], masked: string[]}
 */
function totp_recovery_generate(int $userId, int $count = 10): array
{
    $plain = [];
    $masked = [];
    $hashes = [];
    for ($i = 0; $i < $count; $i++) {
        $raw = strtoupper(bin2hex(random_bytes(5)));   // 10 hex chars
        $code = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
        $plain[] = $code;
        $masked[] = substr($code, 0, 5) . '-*****';
        $hashes[] = password_hash(str_replace('-', '', $raw), PASSWORD_DEFAULT);
    }
    execute('UPDATE ' . t('users') . ' SET recovery_codes = ? WHERE id = ?', [json_encode($hashes), $userId]);
    return ['plain' => $plain, 'masked' => $masked];
}

/**
 * 校验并消耗一个恢复码（用过即失效）。
 */
function totp_recovery_consume(int $userId, string $code): bool
{
    $normalized = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $code));
    if (strlen($normalized) !== 10) {
        return false;
    }
    $row = fetch_one('SELECT recovery_codes FROM ' . t('users') . ' WHERE id = ?', [$userId]);
    $hashes = json_decode((string)($row['recovery_codes'] ?? '[]'), true);
    if (!is_array($hashes) || $hashes === []) {
        return false;
    }
    foreach ($hashes as $index => $hash) {
        if (password_verify($normalized, (string)$hash)) {
            unset($hashes[$index]);
            execute('UPDATE ' . t('users') . ' SET recovery_codes = ? WHERE id = ?', [json_encode(array_values($hashes)), $userId]);
            return true;
        }
    }
    return false;
}

function totp_recovery_remaining(int $userId): int
{
    $row = fetch_one('SELECT recovery_codes FROM ' . t('users') . ' WHERE id = ?', [$userId]);
    $hashes = json_decode((string)($row['recovery_codes'] ?? '[]'), true);
    return is_array($hashes) ? count($hashes) : 0;
}
