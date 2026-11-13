<?php
declare(strict_types=1);

/**
 * 站点级权限（user_sites）
 *
 * 规则（保持向后兼容，不改变既有账号的行为）：
 *   - admin 角色永远能看到全部站点；
 *   - 其他角色若在 user_sites 中**存在至少一条授权**，则只能看到被授权的站点；
 *   - 若一条授权都没有，视为「未做限制」，可见全部站点（旧账号升级后不会被锁住）。
 *
 * 判定同时用于：站点列表、站点详情、站点写操作、站点 API。
 */

function site_scope_map(): array
{
    if (isset($GLOBALS['__tarsk_site_scope'])) {
        return $GLOBALS['__tarsk_site_scope'];
    }
    $cache = [];
    try {
        $rows = fetch_all('SELECT user_id, site_id FROM ' . t('user_sites'));
        foreach ($rows as $row) {
            $cache[(int)$row['user_id']][] = (int)$row['site_id'];
        }
    } catch (Throwable $e) {
        $cache = [];
    }
    $GLOBALS['__tarsk_site_scope'] = $cache;
    return $cache;
}

function user_site_ids(int $userId): array
{
    $map = site_scope_map();
    return $map[$userId] ?? [];
}

function user_has_site_scope(int $userId): bool
{
    return count(user_site_ids($userId)) > 0;
}

/**
 * 当前（或指定）用户可访问的站点 ID
 *
 * @return array|null null = 不受限（全部站点）
 */
function site_scope_ids(?array $user = null): ?array
{
    $user = $user ?? current_user();
    if (!$user) {
        return [];
    }
    if (authz_role_of($user) === 'admin') {
        return null;
    }
    $ids = user_site_ids((int)$user['id']);
    return $ids === [] ? null : $ids;
}

function site_allowed(int $siteId, ?array $user = null): bool
{
    $scope = site_scope_ids($user);
    if ($scope === null) {
        return true;
    }
    return in_array($siteId, $scope, true);
}

/**
 * 生成拼接到 WHERE 后面的片段，形如 " AND `id` IN (1,2)"
 * 未受限时返回空串；受限但没有授权站点时返回 " AND 1=0"
 */
function site_scope_sql(string $column = 'id', ?array $user = null): string
{
    $scope = site_scope_ids($user);
    if ($scope === null) {
        return '';
    }
    if ($scope === []) {
        return ' AND 1=0';
    }
    $safe = array_map('intval', $scope);
    return ' AND `' . preg_replace('/[^A-Za-z0-9_]/', '', $column) . '` IN (' . implode(',', $safe) . ')';
}

function sites_visible(array $sites, ?array $user = null): array
{
    $scope = site_scope_ids($user);
    if ($scope === null) {
        return $sites;
    }
    return array_values(array_filter($sites, static fn($site) => in_array((int)$site['id'], $scope, true)));
}

function site_deny(): void
{
    http_response_code(403);
    render('403', [
        'title' => '权限不足',
        'active' => '',
        'message' => '当前账号未被授权访问该站点，请联系管理员在「用户管理 → 站点权限」中分配。',
        'role' => authz_role_of(current_user()),
    ], 'app');
    exit;
}

/**
 * 取站点并校验访问权限；不存在 → 提示后回列表，无权 → 403
 */
function site_find_visible(int $id): array
{
    $site = fetch_one('SELECT * FROM ' . t('sites') . ' WHERE id = ?', [$id]);
    if (!$site) {
        flash('error', '站点不存在');
        redirect('/sites');
    }
    if (!site_allowed($id)) {
        site_deny();
    }
    return $site;
}

/**
 * 保存某用户的站点授权（传空数组表示取消限制，恢复全站可见）
 */
function user_sites_set(int $userId, array $siteIds): void
{
    execute('DELETE FROM ' . t('user_sites') . ' WHERE user_id = ?', [$userId]);
    $siteIds = array_values(array_unique(array_filter(array_map('intval', $siteIds))));
    if ($siteIds === []) {
        site_scope_map_reset();
        return;
    }
    $valid = fetch_all('SELECT id FROM ' . t('sites'));
    $validIds = array_map(static fn($row) => (int)$row['id'], $valid);
    foreach ($siteIds as $siteId) {
        if (!in_array($siteId, $validIds, true)) {
            continue;
        }
        execute(
            'INSERT INTO ' . t('user_sites') . ' (user_id, site_id, created_at) VALUES (?,?,?)',
            [$userId, $siteId, now()]
        );
    }
    site_scope_map_reset();
}

function site_scope_map_reset(): void
{
    unset($GLOBALS['__tarsk_site_scope']);
}

/**
 * 当前用户是否受站点限制（用于页面提示）
 */
function site_scope_limited(?array $user = null): bool
{
    return site_scope_ids($user) !== null;
}
