<?php
/**
 * WorkFolio PHP - activity log (the audit trail behind /admin/activity).
 * Agent state changes and web actions append rows here.
 */
require_once __DIR__ . '/core.php';

function activity_log(?array $emp, string $action, string $details = '', string $source = 'web'): void {
    try {
        q('INSERT INTO activity_log (email, full_name, role, action, details, source, created_at) VALUES (?,?,?,?,?,?,?)', [
            $emp['email'] ?? null,
            $emp === null ? null : employee_full_name($emp),
            $emp['role'] ?? null,
            $action,
            $details,
            $source,
            now_sql(),
        ]);
    } catch (Throwable $e) {
        /* the audit trail must never break the main flow */
    }
}

function activity_recent(int $limit, ?string $email = null): array {
    if ($email !== null) {
        return rows('SELECT * FROM activity_log WHERE email=? ORDER BY id DESC LIMIT ' . (int) max(1, $limit), [$email]);
    }
    return rows('SELECT * FROM activity_log ORDER BY id DESC LIMIT ' . (int) max(1, $limit));
}

function activity_storage_bytes(): int {
    return shots_storage_bytes();
}