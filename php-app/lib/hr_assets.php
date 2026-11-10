<?php
/**
 * WorkFolio PHP - HR module data layer: Assets.
 *
 * Additive like the other HR layers. Categories group assets; an asset carries
 * a lifecycle status (AVAILABLE -> ASSIGNED -> AVAILABLE / MAINTENANCE ->
 * RETIRED). Assignments are a history table: the open row (returned_at IS NULL)
 * is the current holder, so returning an asset closes it and frees the asset.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/hr.php';              /* hr_int_or_null() */

/* ---------------------------------------------------------------- lookups ---- */

function hr_asset_statuses(): array {
    return ['AVAILABLE' => 'Available', 'ASSIGNED' => 'Assigned', 'RETURNED' => 'Returned',
            'MAINTENANCE' => 'Maintenance', 'RETIRED' => 'Retired', 'LOST' => 'Lost'];
}

function hr_asset_status_label(string $s): string {
    return hr_asset_statuses()[strtoupper(trim($s))] ?? 'Available';
}

function hr_asset_status_badge(string $s): string {
    $map = ['AVAILABLE' => 'bg-success', 'ASSIGNED' => 'bg-primary', 'RETURNED' => 'bg-info text-dark',
            'MAINTENANCE' => 'bg-warning text-dark', 'RETIRED' => 'bg-secondary', 'LOST' => 'bg-danger'];
    $s = strtoupper(trim($s)) ?: 'AVAILABLE';
    return '<span class="badge ' . esc($map[$s] ?? 'bg-secondary') . '">' . esc(hr_asset_status_label($s)) . '</span>';
}

function hr_asset_conditions(): array {
    return ['NEW' => 'New', 'GOOD' => 'Good', 'FAIR' => 'Fair', 'DAMAGED' => 'Damaged'];
}

function hr_asset_condition_label(string $c): string {
    return hr_asset_conditions()[strtoupper(trim($c))] ?? 'Good';
}

/* ---------------------------------------------------------------- cleaning ---- */

function ha_text(string $v, int $max): string {
    $v = str_replace("\0", '', $v);
    $v = preg_replace('/\s+/u', ' ', trim($v)) ?? trim($v);
    return substr($v, 0, $max);
}

function ha_money(string $v): string {
    $v = preg_replace('/[^0-9.\-]/', '', (string) $v) ?? '';
    return $v === '' ? '0' : substr($v, 0, 40);
}

function ha_num($v): string {
    return is_numeric($v) ? (string) $v : '0';
}

/* ------------------------------------------------------------- categories ---- */

function hr_asset_categories(bool $fresh = false): array {
    static $cache = null;
    if ($fresh) $cache = null;
    if ($cache === null) {
        try { $cache = rows('SELECT * FROM hr_asset_categories ORDER BY name ASC'); }
        catch (Throwable $e) { $cache = []; }
    }
    return $cache;
}

function hr_asset_category_map(): array {
    $m = [];
    foreach (hr_asset_categories() as $c) $m[(int) $c['id']] = (string) $c['name'];
    return $m;
}

function hr_asset_category_save(array $d): int {
    $id = hr_int_or_null($d['id'] ?? null);
    $name = ha_text((string) ($d['name'] ?? ''), 160);
    if ($name === '') return 0;
    $code = strtoupper(ha_text((string) ($d['code'] ?? ''), 60));
    if ($code === '') $code = strtoupper(preg_replace('/[^A-Z0-9]+/i', '_', $name) ?? 'CAT');
    if ($id) {
        q('UPDATE hr_asset_categories SET name=?, code=? WHERE id=?', [$name, $code, $id]);
        hr_asset_categories(true);
        return $id;
    }
    q('INSERT INTO hr_asset_categories (name,code,created_at) VALUES (?,?,?)', [$name, $code, now_sql()]);
    $new = last_id();
    hr_asset_categories(true);
    return $new;
}

function hr_asset_category_delete(int $id): void {
    q('UPDATE hr_assets SET category_id=NULL WHERE category_id=?', [$id]);
    q('DELETE FROM hr_asset_categories WHERE id=?', [$id]);
    hr_asset_categories(true);
}

/* ----------------------------------------------------------------- assets ---- */

function hr_assets(array $filters = []): array {
    $sql = 'SELECT a.*, c.name AS category_name,
                   (SELECT x.email FROM hr_asset_assignments x WHERE x.asset_id=a.id AND x.returned_at IS NULL ORDER BY x.id DESC LIMIT 1) AS holder
            FROM hr_assets a LEFT JOIN hr_asset_categories c ON c.id = a.category_id';
    $where = []; $p = [];
    if (!empty($filters['status'])) { $where[] = 'a.status = ?'; $p[] = strtoupper((string) $filters['status']); }
    if (!empty($filters['category_id'])) { $where[] = 'a.category_id = ?'; $p[] = (int) $filters['category_id']; }
    if (!empty($filters['q'])) {
        $where[] = '(a.name LIKE ? OR a.asset_tag LIKE ? OR a.serial_no LIKE ? OR a.brand LIKE ?)';
        $like = '%' . (string) $filters['q'] . '%';
        array_push($p, $like, $like, $like, $like);
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY a.id DESC';
    if (!empty($filters['limit'])) $sql .= ' LIMIT ' . (int) $filters['limit'];
    return rows($sql, $p);
}

function hr_asset(?int $id): ?array {
    return $id ? one('SELECT * FROM hr_assets WHERE id=?', [$id]) : null;
}

function hr_asset_by_tag(string $tag): ?array {
    $tag = ha_text($tag, 80);
    if ($tag === '') return null;
    return one('SELECT * FROM hr_assets WHERE asset_tag=?', [$tag]);
}

function hr_asset_save(?int $id, array $d, string $by): int {
    $name = ha_text((string) ($d['name'] ?? ''), 200);
    if ($name === '') return 0;
    $tag = ha_text((string) ($d['asset_tag'] ?? ''), 80);
    if ($tag === '') $tag = 'AST-' . strtoupper(bin2hex(random_bytes(3)));
    $status = strtoupper(trim((string) ($d['status'] ?? 'AVAILABLE')));
    if (!isset(hr_asset_statuses()[$status])) $status = 'AVAILABLE';
    $cond = strtoupper(trim((string) ($d['asset_condition'] ?? 'GOOD')));
    if (!isset(hr_asset_conditions()[$cond])) $cond = 'GOOD';
    $catId = hr_int_or_null($d['category_id'] ?? null);
    $fields = [
        'asset_tag' => $tag, 'name' => $name, 'category_id' => $catId,
        'serial_no' => ha_text((string) ($d['serial_no'] ?? ''), 160),
        'brand' => ha_text((string) ($d['brand'] ?? ''), 120),
        'model' => ha_text((string) ($d['model'] ?? ''), 160),
        'purchase_date' => ha_text((string) ($d['purchase_date'] ?? ''), 20),
        'purchase_cost' => ha_money((string) ($d['purchase_cost'] ?? '0')),
        'warranty_until' => ha_text((string) ($d['warranty_until'] ?? ''), 20),
        'status' => $status, 'asset_condition' => $cond,
        'notes' => substr(str_replace("\0", '', (string) ($d['notes'] ?? '')), 0, 8000),
    ];
    if ($id) {
        /* Guard the tag uniqueness without relying on a thrown DB error. */
        $clash = one('SELECT id FROM hr_assets WHERE asset_tag=? AND id<>?', [$tag, $id]);
        if ($clash !== null) return 0;
        q('UPDATE hr_assets SET asset_tag=?, name=?, category_id=?, serial_no=?, brand=?, model=?,
           purchase_date=?, purchase_cost=?, warranty_until=?, status=?, asset_condition=?, notes=?, updated_at=?
           WHERE id=?', array_merge(array_values($fields), [now_sql(), $id]));
        return $id;
    }
    if (hr_asset_by_tag($tag) !== null) return 0;
    q('INSERT INTO hr_assets (asset_tag,name,category_id,serial_no,brand,model,purchase_date,purchase_cost,
       warranty_until,status,asset_condition,notes,created_by,created_at,updated_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        array_merge(array_values($fields), [strtolower(trim($by)), now_sql(), now_sql()]));
    return last_id();
}

function hr_asset_delete(int $id): void {
    q('DELETE FROM hr_asset_assignments WHERE asset_id=?', [$id]);
    q('DELETE FROM hr_assets WHERE id=?', [$id]);
}

/* ------------------------------------------------------------ assignments ---- */

function hr_asset_current_assignment(int $assetId): ?array {
    return one('SELECT * FROM hr_asset_assignments WHERE asset_id=? AND returned_at IS NULL ORDER BY id DESC LIMIT 1', [$assetId]);
}

function hr_asset_assignments(int $assetId): array {
    return rows('SELECT * FROM hr_asset_assignments WHERE asset_id=? ORDER BY id DESC', [$assetId]);
}

function hr_asset_assign(int $assetId, string $email, string $by, string $condition = 'GOOD', string $notes = ''): bool {
    $asset = hr_asset($assetId);
    if ($asset === null) return false;
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    if (hr_asset_current_assignment($assetId) !== null) return false; /* already out */
    $cond = strtoupper(trim($condition));
    if (!isset(hr_asset_conditions()[$cond])) $cond = 'GOOD';
    q('INSERT INTO hr_asset_assignments (asset_id,email,assigned_by,assigned_at,condition_out,notes)
       VALUES (?,?,?,?,?,?)', [$assetId, $email, strtolower(trim($by)), now_sql(), $cond,
        substr(str_replace("\0", '', $notes), 0, 8000)]);
    q('UPDATE hr_assets SET status=?, updated_at=? WHERE id=?', ['ASSIGNED', now_sql(), $assetId]);
    return true;
}

function hr_asset_return(int $assetId, string $by, string $condition = 'GOOD', string $notes = ''): bool {
    $open = hr_asset_current_assignment($assetId);
    if ($open === null) return false;
    $cond = strtoupper(trim($condition));
    if (!isset(hr_asset_conditions()[$cond])) $cond = 'GOOD';
    q('UPDATE hr_asset_assignments SET returned_at=?, condition_in=?, notes=? WHERE id=?',
        [now_sql(), $cond, substr(str_replace("\0", '', $notes), 0, 8000), (int) $open['id']]);
    /* A returned item parks in RETURNED until HR inspects/re-shelves it;
       damaged returns go straight to maintenance. */
    $newStatus = $cond === 'DAMAGED' ? 'MAINTENANCE' : 'RETURNED';
    q('UPDATE hr_assets SET status=?, asset_condition=?, updated_at=? WHERE id=?', [$newStatus, $cond, now_sql(), $assetId]);
    activity_log(['email' => strtolower(trim($by)), 'role' => ''], 'HR_ASSET_RETURNED', 'Asset #' . $assetId);
    return true;
}

function hr_asset_set_status(int $assetId, string $status, string $by): bool {
    $status = strtoupper(trim($status));
    if (!isset(hr_asset_statuses()[$status])) return false;
    if (hr_asset($assetId) === null) return false;
    if ($status === 'ASSIGNED') return false; /* only assignment sets this */
    /* Leaving ASSIGNED via status means we must close the open assignment. */
    $open = hr_asset_current_assignment($assetId);
    if ($open !== null) {
        q('UPDATE hr_asset_assignments SET returned_at=?, condition_in=? WHERE id=?',
            [now_sql(), $status === 'LOST' ? 'DAMAGED' : 'GOOD', (int) $open['id']]);
    }
    q('UPDATE hr_assets SET status=?, updated_at=? WHERE id=?', [$status, now_sql(), $assetId]);
    return true;
}

function hr_assets_for_email(string $email): array {
    $email = strtolower(trim($email));
    if ($email === '') return [];
    return rows('SELECT a.*, x.assigned_at, x.condition_out FROM hr_asset_assignments x
                 JOIN hr_assets a ON a.id = x.asset_id
                 WHERE x.email = ? AND x.returned_at IS NULL ORDER BY a.name ASC', [$email]);
}

function hr_asset_history_for_email(string $email): array {
    $email = strtolower(trim($email));
    if ($email === '') return [];
    return rows('SELECT x.*, a.name AS asset_name, a.asset_tag FROM hr_asset_assignments x
                 JOIN hr_assets a ON a.id = x.asset_id
                 WHERE x.email = ? ORDER BY x.id DESC', [$email]);
}

/* --------------------------------------------------------------- dashboard ---- */

function hr_assets_stats(): array {
    try {
        $total = (int) val('SELECT COUNT(*) FROM hr_assets');
        $available = (int) val("SELECT COUNT(*) FROM hr_assets WHERE status='AVAILABLE'");
        $assigned = (int) val("SELECT COUNT(*) FROM hr_assets WHERE status='ASSIGNED'");
        $maintenance = (int) val("SELECT COUNT(*) FROM hr_assets WHERE status='MAINTENANCE'");
        $returned = (int) val("SELECT COUNT(*) FROM hr_assets WHERE status='RETURNED'");
        $value = (string) val("SELECT COALESCE(SUM(CAST(purchase_cost AS DECIMAL(18,2))),0) FROM hr_assets");
    } catch (Throwable $e) {
        return ['total' => 0, 'available' => 0, 'assigned' => 0, 'returned' => 0, 'maintenance' => 0, 'value' => '0'];
    }
    return ['total' => $total, 'available' => $available, 'assigned' => $assigned,
            'returned' => $returned, 'maintenance' => $maintenance, 'value' => $value];
}
