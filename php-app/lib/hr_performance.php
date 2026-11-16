<?php
/**
 * WorkFolio PHP - HR module data layer: Performance Management.
 *
 * Additive like the other HR layers: it owns only the hr_performance_* tables
 * and reads the roster (employees) by email. A cycle folds reviews for one
 * appraisal period; a review is one employee's appraisal; goals are what they
 * were measured against. A review only becomes ACKNOWLEDGED when the employee
 * signs it off in self-service, which is the record the audit trail wants.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/hr.php';              /* hr_int_or_null() */

/* ---------------------------------------------------------------- lookups ---- */

function hr_performance_cycle_statuses(): array {
    return ['OPEN' => 'Open', 'CLOSED' => 'Closed'];
}

function hr_performance_cycle_status_badge(string $s): string {
    $map = ['OPEN' => 'bg-primary', 'CLOSED' => 'bg-secondary'];
    $labels = hr_performance_cycle_statuses();
    $s = strtoupper(trim($s)) ?: 'OPEN';
    return '<span class="badge ' . esc($map[$s] ?? 'bg-secondary') . '">' . esc($labels[$s] ?? $s) . '</span>';
}

function hr_performance_review_statuses(): array {
    return ['DRAFT' => 'Draft', 'SUBMITTED' => 'Submitted', 'ACKNOWLEDGED' => 'Acknowledged'];
}

function hr_performance_review_status_badge(string $s): string {
    $map = ['DRAFT' => 'bg-secondary', 'SUBMITTED' => 'bg-primary', 'ACKNOWLEDGED' => 'bg-success'];
    $labels = hr_performance_review_statuses();
    $s = strtoupper(trim($s)) ?: 'DRAFT';
    return '<span class="badge ' . esc($map[$s] ?? 'bg-secondary') . '">' . esc($labels[$s] ?? $s) . '</span>';
}

function hr_performance_review_types(): array {
    return ['MANAGER' => 'Manager Review', 'SELF' => 'Self Review', 'PEER' => 'Peer Review'];
}

function hr_performance_goal_statuses(): array {
    return ['ACTIVE' => 'Active', 'ACHIEVED' => 'Achieved', 'MISSED' => 'Missed'];
}

function hr_performance_goal_status_badge(string $s): string {
    $map = ['ACTIVE' => 'bg-primary', 'ACHIEVED' => 'bg-success', 'MISSED' => 'bg-danger'];
    $labels = hr_performance_goal_statuses();
    $s = strtoupper(trim($s)) ?: 'ACTIVE';
    return '<span class="badge ' . esc($map[$s] ?? 'bg-secondary') . '">' . esc($labels[$s] ?? $s) . '</span>';
}

/* ---------------------------------------------------------------- cleaning ---- */

function hpf_text(string $v, int $max): string {
    $v = str_replace("\0", '', $v);
    $v = preg_replace('/\s+/u', ' ', trim($v)) ?? trim($v);
    return substr($v, 0, $max);
}

function hpf_block(string $v, int $max = 8000): string {
    $v = str_replace("\0", '', $v);
    $v = preg_replace("/\r\n?/", "\n", trim($v)) ?? trim($v);
    return substr($v, 0, $max);
}

function hpf_date(?string $v): string {
    $v = trim((string) $v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
}

function hpf_rating($v): int {
    return max(0, min(5, (int) $v));
}

/* ------------------------------------------------------------------ cycles ---- */

function hr_performance_cycles(array $filters = []): array {
    $sql = 'SELECT c.*, (SELECT COUNT(*) FROM hr_performance_reviews r WHERE r.cycle_id = c.id) AS review_count
            FROM hr_performance_cycles c';
    $where = []; $p = [];
    if (!empty($filters['status'])) { $where[] = 'c.status = ?'; $p[] = strtoupper((string) $filters['status']); }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY c.id DESC';
    if (!empty($filters['limit'])) $sql .= ' LIMIT ' . (int) $filters['limit'];
    return rows($sql, $p);
}

function hr_performance_cycle(?int $id): ?array {
    return $id ? one('SELECT * FROM hr_performance_cycles WHERE id=?', [$id]) : null;
}

function hr_performance_cycle_save(?int $id, array $d, string $by): int {
    $name = hpf_text((string) ($d['name'] ?? ''), 160);
    if ($name === '') return 0;
    $status = strtoupper(trim((string) ($d['status'] ?? 'OPEN')));
    if (!isset(hr_performance_cycle_statuses()[$status])) $status = 'OPEN';
    $vals = [
        $name,
        hpf_date($d['period_start'] ?? null),
        hpf_date($d['period_end'] ?? null),
        $status,
        hpf_block((string) ($d['description'] ?? '')),
    ];
    if ($id) {
        $vals[] = now_sql();
        $vals[] = $id;
        q('UPDATE hr_performance_cycles SET name=?, period_start=?, period_end=?, status=?, description=?, updated_at=?
           WHERE id=?', $vals);
        return $id;
    }
    $vals[] = strtolower(trim($by));
    $vals[] = now_sql();
    $vals[] = now_sql();
    q('INSERT INTO hr_performance_cycles (name,period_start,period_end,status,description,created_by,created_at,updated_at)
       VALUES (?,?,?,?,?,?,?,?)', $vals);
    return last_id();
}

function hr_performance_cycle_delete(int $id): void {
    q('UPDATE hr_performance_reviews SET cycle_id=NULL WHERE cycle_id=?', [$id]);
    q('UPDATE hr_performance_goals SET cycle_id=NULL WHERE cycle_id=?', [$id]);
    q('DELETE FROM hr_performance_cycles WHERE id=?', [$id]);
}

/* ----------------------------------------------------------------- reviews ---- */

function hr_performance_reviews(array $filters = []): array {
    $sql = 'SELECT r.*, c.name AS cycle_name, e.first_name, e.last_name
            FROM hr_performance_reviews r
            LEFT JOIN hr_performance_cycles c ON c.id = r.cycle_id
            LEFT JOIN employees e ON e.email = r.email';
    $where = []; $p = [];
    if (!empty($filters['cycle_id'])) { $where[] = 'r.cycle_id = ?'; $p[] = (int) $filters['cycle_id']; }
    if (!empty($filters['email']))    { $where[] = 'r.email = ?'; $p[] = strtolower((string) $filters['email']); }
    if (!empty($filters['status']))   { $where[] = 'r.status = ?'; $p[] = strtoupper((string) $filters['status']); }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY r.id DESC';
    if (!empty($filters['limit'])) $sql .= ' LIMIT ' . (int) $filters['limit'];
    return rows($sql, $p);
}

function hr_performance_review(?int $id): ?array {
    return $id ? one('SELECT * FROM hr_performance_reviews WHERE id=?', [$id]) : null;
}

/** The single review for one person in one cycle, if it exists. */
function hr_performance_review_for(string $email, int $cycleId): ?array {
    $email = strtolower(trim($email));
    if ($email === '' || $cycleId <= 0) return null;
    return one('SELECT * FROM hr_performance_reviews WHERE email=? AND cycle_id=? ORDER BY id DESC LIMIT 1',
        [$email, $cycleId]);
}

function hr_performance_review_save(?int $id, array $d, string $by): int {
    $email = strtolower(hpf_text((string) ($d['email'] ?? ''), 190));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 0;
    $cycleId = hr_int_or_null($d['cycle_id'] ?? null);
    $type = strtoupper(trim((string) ($d['review_type'] ?? 'MANAGER')));
    if (!isset(hr_performance_review_types()[$type])) $type = 'MANAGER';
    $rating = hpf_rating($d['overall_rating'] ?? 0);
    $self = hpf_rating($d['self_rating'] ?? 0);
    $vals = [
        $cycleId, $email,
        strtolower(hpf_text((string) ($d['reviewer_email'] ?? ''), 190)),
        $type, $rating, $self,
        hpf_block((string) ($d['strengths'] ?? '')),
        hpf_block((string) ($d['improvements'] ?? '')),
        hpf_block((string) ($d['comments'] ?? '')),
        hpf_block((string) ($d['manager_comments'] ?? '')),
    ];
    if ($id) {
        $vals[] = now_sql();
        $vals[] = $id;
        q('UPDATE hr_performance_reviews SET cycle_id=?, email=?, reviewer_email=?, review_type=?, overall_rating=?,
           self_rating=?, strengths=?, improvements=?, comments=?, manager_comments=?, updated_at=? WHERE id=?', $vals);
        return $id;
    }
    $vals[] = strtolower(trim($by));
    $vals[] = now_sql();
    $vals[] = now_sql();
    q('INSERT INTO hr_performance_reviews (cycle_id,email,reviewer_email,review_type,overall_rating,self_rating,
       strengths,improvements,comments,manager_comments,created_by,created_at,updated_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', $vals);
    return last_id();
}

/** Move DRAFT -> SUBMITTED. Records the moment the reviewer filed it. */
function hr_performance_review_submit(int $id, string $by): bool {
    $r = hr_performance_review($id);
    if ($r === null) return false;
    q('UPDATE hr_performance_reviews SET status=?, submitted_at=?, updated_at=? WHERE id=?',
        ['SUBMITTED', now_sql(), now_sql(), $id]);
    activity_log(['email' => $by, 'role' => ''], 'HR_PERFORMANCE_SUBMITTED', 'Review #' . $id);
    return true;
}

/** The employee signs off their own review. Only allowed once SUBMITTED. */
function hr_performance_review_acknowledge(int $id, string $email): bool {
    $r = hr_performance_review($id);
    if ($r === null) return false;
    if (strtolower((string) $r['email']) !== strtolower(trim($email))) return false;
    if (strtoupper((string) $r['status']) !== 'SUBMITTED') return false;
    q('UPDATE hr_performance_reviews SET status=?, acknowledged_at=?, updated_at=? WHERE id=?',
        ['ACKNOWLEDGED', now_sql(), now_sql(), $id]);
    activity_log(['email' => $email, 'role' => ''], 'HR_PERFORMANCE_ACKNOWLEDGED', 'Review #' . $id);
    return true;
}

/** An employee may save their self-rating/comments while the review is editable. */
function hr_performance_review_save_self(int $id, string $email, array $d): bool {
    $r = hr_performance_review($id);
    if ($r === null) return false;
    if (strtolower((string) $r['email']) !== strtolower(trim($email))) return false;
    if (strtoupper((string) $r['status']) === 'ACKNOWLEDGED') return false;
    q('UPDATE hr_performance_reviews SET self_rating=?, comments=?, updated_at=? WHERE id=?',
        [hpf_rating($d['self_rating'] ?? 0), hpf_block((string) ($d['comments'] ?? '')), now_sql(), $id]);
    return true;
}

function hr_performance_review_delete(int $id): void {
    q('UPDATE hr_performance_goals SET review_id=NULL WHERE review_id=?', [$id]);
    q('DELETE FROM hr_performance_reviews WHERE id=?', [$id]);
}

/**
 * Start a DRAFT review for every visible employee who does not already have one
 * in the cycle. Returns the number created so the caller can flash it.
 */
function hr_performance_reviews_generate(int $cycleId, array $emails, string $by): int {
    if (hr_performance_cycle($cycleId) === null) return 0;
    $n = 0;
    foreach ($emails as $email) {
        $email = strtolower(trim((string) $email));
        if ($email === '' || hr_performance_review_for($email, $cycleId) !== null) continue;
        if (hr_performance_review_save(null, ['email' => $email, 'cycle_id' => $cycleId] + ['review_type' => 'MANAGER'], $by) > 0) {
            $n++;
        }
    }
    return $n;
}

/* ------------------------------------------------------------------- goals ---- */

function hr_performance_goals(array $filters = []): array {
    $sql = 'SELECT g.*, c.name AS cycle_name, e.first_name, e.last_name
            FROM hr_performance_goals g
            LEFT JOIN hr_performance_cycles c ON c.id = g.cycle_id
            LEFT JOIN employees e ON e.email = g.email';
    $where = []; $p = [];
    if (!empty($filters['email']))    { $where[] = 'g.email = ?'; $p[] = strtolower((string) $filters['email']); }
    if (!empty($filters['cycle_id'])) { $where[] = 'g.cycle_id = ?'; $p[] = (int) $filters['cycle_id']; }
    if (!empty($filters['status']))   { $where[] = 'g.status = ?'; $p[] = strtoupper((string) $filters['status']); }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY g.id DESC';
    if (!empty($filters['limit'])) $sql .= ' LIMIT ' . (int) $filters['limit'];
    return rows($sql, $p);
}

function hr_performance_goal(?int $id): ?array {
    return $id ? one('SELECT * FROM hr_performance_goals WHERE id=?', [$id]) : null;
}

function hr_performance_goal_save(?int $id, array $d, string $by): int {
    $email = strtolower(hpf_text((string) ($d['email'] ?? ''), 190));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 0;
    $title = hpf_text((string) ($d['title'] ?? ''), 200);
    if ($title === '') return 0;
    $status = strtoupper(trim((string) ($d['status'] ?? 'ACTIVE')));
    if (!isset(hr_performance_goal_statuses()[$status])) $status = 'ACTIVE';
    $progress = max(0, min(100, (int) ($d['progress'] ?? 0)));
    $weight = max(0, min(100, (int) ($d['weight'] ?? 0)));
    $vals = [
        $email,
        hr_int_or_null($d['cycle_id'] ?? null),
        hr_int_or_null($d['review_id'] ?? null),
        $title,
        hpf_block((string) ($d['description'] ?? '')),
        $weight, $progress, $status,
        hpf_date($d['due_date'] ?? null),
    ];
    if ($id) {
        $vals[] = now_sql();
        $vals[] = $id;
        q('UPDATE hr_performance_goals SET email=?, cycle_id=?, review_id=?, title=?, description=?, weight=?,
           progress=?, status=?, due_date=?, updated_at=? WHERE id=?', $vals);
        return $id;
    }
    $vals[] = strtolower(trim($by));
    $vals[] = now_sql();
    $vals[] = now_sql();
    q('INSERT INTO hr_performance_goals (email,cycle_id,review_id,title,description,weight,progress,status,due_date,
       created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', $vals);
    return last_id();
}

function hr_performance_goal_delete(int $id): void { q('DELETE FROM hr_performance_goals WHERE id=?', [$id]); }

/* --------------------------------------------------------------- dashboard ---- */

function hr_performance_stats(): array {
    try {
        $openCycles = (int) val("SELECT COUNT(*) FROM hr_performance_cycles WHERE status='OPEN'");
        $pending = (int) val("SELECT COUNT(*) FROM hr_performance_reviews WHERE status IN ('DRAFT','SUBMITTED')");
        $ackMonth = (int) val("SELECT COUNT(*) FROM hr_performance_reviews WHERE status='ACKNOWLEDGED' AND acknowledged_at LIKE ?",
            [date('Y-m') . '%']);
        $activeGoals = (int) val("SELECT COUNT(*) FROM hr_performance_goals WHERE status='ACTIVE'");
    } catch (Throwable $e) {
        return ['open_cycles' => 0, 'pending_reviews' => 0, 'ack_month' => 0, 'active_goals' => 0];
    }
    return ['open_cycles' => $openCycles, 'pending_reviews' => $pending,
            'ack_month' => $ackMonth, 'active_goals' => $activeGoals];
}
