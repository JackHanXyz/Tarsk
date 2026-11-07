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
