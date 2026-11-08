<?php
declare(strict_types=1);

/**
 * 安全策略：登录失败封禁、面板 IP 白名单、访问来源校验
 */

/**
 * IP 是否命中白名单规则（支持精确、通配 *、CIDR）
 */
function security_ip_match(string $ip, string $rule): bool
{
    $rule = trim($rule);
    if ($rule === '') {
        return false;
    }
    if (str_contains($rule, '/')) {
        [$subnet, $bits] = array_pad(explode('/', $rule, 2), 2, '32');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $bits = max(0, min(32, (int)$bits));
            $mask = $bits === 0 ? 0 : (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;
            $ipLong = ip2long($ip);
            $netLong = ip2long($subnet);
            return $ipLong !== false && $netLong !== false && (($ipLong & $mask) === ($netLong & $mask));
        }
        return false;
    }
    if (str_contains($rule, '*')) {
        $pattern = '/^' . str_replace(['.', '*'], ['\.', '\d{1,3}'], $rule) . '$/';
        return (bool)preg_match($pattern, $ip);
    }
    return $ip === $rule;
}

/**
 * 面板 IP 白名单：为空时放行全部
 */
function security_ip_allowed(string $ip): bool
{
    $raw = trim((string)setting('panel_ip_allowlist', ''));
    if ($raw === '') {
        return true;
    }
    foreach (preg_split('/[\s,;]+/', $raw) as $rule) {
        if ($rule !== '' && security_ip_match($ip, $rule)) {
            return true;
        }
    }
    return false;
}

function security_cleanup_attempts(): void
{
    execute('DELETE FROM ' . t('login_attempts') . " WHERE updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
}

/**
 * 当前是否处于锁定，返回剩余秒数（0 表示未锁定）
 */
function login_guard_remaining(string $ip, string $username): int
{
    try {
        security_cleanup_attempts();
        $row = fetch_one(
            'SELECT * FROM ' . t('login_attempts') . ' WHERE ip = ? AND username = ?',
            [$ip, $username]
        );
        if (!$row || $row['locked_until'] === null) {
            return 0;
        }
        $left = strtotime((string)$row['locked_until']) - time();
        if ($left > 0) {
            return $left;
        }
        execute(
            'UPDATE ' . t('login_attempts') . ' SET attempts = 0, locked_until = NULL, updated_at = ? WHERE id = ?',
            [now(), $row['id']]
        );
        return 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * 记录一次登录失败，达到阈值即锁定
 */
function login_guard_fail(string $ip, string $username): array
{
    $max = max(3, (int)setting('login_max_fail', '5'));
    $lockMinutes = max(1, (int)setting('login_lock_minutes', '15'));
    $row = fetch_one(
        'SELECT * FROM ' . t('login_attempts') . ' WHERE ip = ? AND username = ?',
        [$ip, $username]
    );
    $attempts = ((int)($row['attempts'] ?? 0)) + 1;
    $locked = $attempts >= $max;
    $lockedUntil = $locked ? date('Y-m-d H:i:s', time() + $lockMinutes * 60) : null;

    if ($row) {
        execute(
            'UPDATE ' . t('login_attempts') . ' SET attempts = ?, locked_until = ?, updated_at = ? WHERE id = ?',
            [$attempts, $lockedUntil, now(), $row['id']]
        );
    } else {
        execute(
            'INSERT INTO ' . t('login_attempts') . ' (ip, username, attempts, locked_until, updated_at) VALUES (?,?,?,?,?)',
            [$ip, $username, $attempts, $lockedUntil, now()]
        );
    }

    return [
        'locked' => $locked,
        'attempts' => $attempts,
        'max' => $max,
        'remaining' => $locked ? $lockMinutes : max(0, $max - $attempts),
    ];
}

function login_guard_reset(string $ip, string $username): void
{
    execute('DELETE FROM ' . t('login_attempts') . ' WHERE ip = ? AND username = ?', [$ip, $username]);
}

/**
 * 当前锁定记录列表（供安全设置页展示）
 */
function login_locked_list(): array
{
    $rows = fetch_all(
        'SELECT * FROM ' . t('login_attempts') . ' WHERE locked_until IS NOT NULL AND locked_until > ? ORDER BY locked_until DESC LIMIT 50',
        [now()]
    );
    foreach ($rows as &$row) {
        $row['remaining'] = max(0, (int)((strtotime((string)$row['locked_until']) - time()) / 60));
    }
    unset($row);
    return $rows;
}
