<?php
/**
 * WorkFolio PHP - HR module data layer: Tasks & Work.
 *
 * Additive like the other HR layers: it owns only the hr_tasks / hr_task_comments
 * tables and reads the roster (employees) by email. A task is keyed by assignee
 * email so a manager can hand work to anyone on the roster; comments are the
 * thread on a task. Status moves are recorded with a completion timestamp.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/hr.php';              /* hr_int_or_null() */

/* ---------------------------------------------------------------- lookups ---- */

function hr_task_statuses(): array {
    return ['TODO' => 'To Do', 'IN_PROGRESS' => 'In Progress', 'REVIEW' => 'In Review',
            'DONE' => 'Done', 'CANCELLED' => 'Cancelled'];
}

/** The open pipeline, in board order. DONE/CANCELLED are terminal. */
function hr_task_open_statuses(): array {
    return ['TODO', 'IN_PROGRESS', 'REVIEW'];
}

function hr_task_status_badge(string $s): string {
    $map = ['TODO' => 'bg-secondary', 'IN_PROGRESS' => 'bg-primary', 'REVIEW' => 'bg-warning text-dark',
            'DONE' => 'bg-success', 'CANCELLED' => 'bg-dark'];
    $labels = hr_task_statuses();
    $s = strtoupper(trim($s)) ?: 'TODO';
    return '<span class="badge ' . esc($map[$s] ?? 'bg-secondary') . '">' . esc($labels[$s] ?? $s) . '</span>';
}

function hr_task_priorities(): array {
    return ['LOW' => 'Low', 'MEDIUM' => 'Medium', 'HIGH' => 'High', 'URGENT' => 'Urgent'];
}

function hr_task_priority_badge(string $p): string {
    $map = ['LOW' => 'bg-info text-dark', 'MEDIUM' => 'bg-secondary', 'HIGH' => 'bg-warning text-dark',
            'URGENT' => 'bg-danger'];
    $labels = hr_task_priorities();
    $p = strtoupper(trim($p)) ?: 'MEDIUM';
    return '<span class="badge ' . esc($map[$p] ?? 'bg-secondary') . '">' . esc($labels[$p] ?? $p) . '</span>';
}

/* ---------------------------------------------------------------- cleaning ---- */

function hts_text(string $v, int $max): string {
    $v = str_replace("\0", '', $v);
    $v = preg_replace('/\s+/u', ' ', trim($v)) ?? trim($v);
    return substr($v, 0, $max);
}

function hts_block(string $v, int $max = 8000): string {
    $v = str_replace("\0", '', $v);
    $v = preg_replace("/\r\n?/", "\n", trim($v)) ?? trim($v);
    return substr($v, 0, $max);
}

function hts_date(?string $v): string {
    $v = trim((string) $v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
}

/* ------------------------------------------------------------------- tasks ---- */

function hr_tasks(array $filters = []): array {
    $sql = 'SELECT t.*, e.first_name, e.last_name
            FROM hr_tasks t
            LEFT JOIN employees e ON e.email = t.assignee_email';
    $where = []; $p = [];
    if (!empty($filters['assignee']))  { $where[] = 't.assignee_email = ?'; $p[] = strtolower((string) $filters['assignee']); }
    if (!empty($filters['status']))    { $where[] = 't.status = ?'; $p[] = strtoupper((string) $filters['status']); }
    if (!empty($filters['priority']))  { $where[] = 't.priority = ?'; $p[] = strtoupper((string) $filters['priority']); }
    if (!empty($filters['department_id'])) { $where[] = 't.department_id = ?'; $p[] = (int) $filters['department_id']; }
    if (!empty($filters['open_only'])) { $where[] = "t.status IN ('TODO','IN_PROGRESS','REVIEW')"; }
    if (!empty($filters['q']))         { $where[] = '(t.title LIKE ? OR t.description LIKE ?)'; $like = '%' . $filters['q'] . '%'; $p[] = $like; $p[] = $like; }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY (t.due_date = \'\' OR t.due_date IS NULL) ASC, t.due_date ASC, t.id DESC';
    if (!empty($filters['limit'])) $sql .= ' LIMIT ' . (int) $filters['limit'];
    return rows($sql, $p);
}

function hr_task(?int $id): ?array {
    return $id ? one('SELECT * FROM hr_tasks WHERE id=?', [$id]) : null;
}

function hr_task_save(?int $id, array $d, string $by): int {
    $title = hts_text((string) ($d['title'] ?? ''), 200);
    if ($title === '') return 0;
    $status = strtoupper(trim((string) ($d['status'] ?? 'TODO')));
    if (!isset(hr_task_statuses()[$status])) $status = 'TODO';
    $priority = strtoupper(trim((string) ($d['priority'] ?? 'MEDIUM')));
    if (!isset(hr_task_priorities()[$priority])) $priority = 'MEDIUM';
    $progress = max(0, min(100, (int) ($d['progress'] ?? 0)));
    $vals = [
        $title,
        hts_block((string) ($d['description'] ?? '')),
        strtolower(hts_text((string) ($d['assignee_email'] ?? ''), 190)),
        strtolower(hts_text($by, 190) ?: (string) ($d['assigned_by'] ?? '')),
        hr_int_or_null($d['department_id'] ?? null),
        hr_int_or_null($d['branch_id'] ?? null),
        $priority, $status, $progress,
        hts_date($d['start_date'] ?? null),
        hts_date($d['due_date'] ?? null),
    ];
    $completed = $status === 'DONE' ? now_sql() : null;
    if ($id) {
        $vals[] = $completed;
        $vals[] = now_sql();
        $vals[] = $id;
        q('UPDATE hr_tasks SET title=?, description=?, assignee_email=?, assigned_by=?, department_id=?, branch_id=?,
           priority=?, status=?, progress=?, start_date=?, due_date=?, completed_at=?, updated_at=? WHERE id=?', $vals);
        if (isset($vals[2]) && $vals[2] !== '' && (string) $vals[2] !== strtolower((string) $by)) {
            hr_task_assigned_notify((int) $id, $title, (string) $vals[2]);
        }
        return $id;
    }
    $vals[] = $completed;
    $vals[] = now_sql();
    $vals[] = now_sql();
    q('INSERT INTO hr_tasks (title,description,assignee_email,assigned_by,department_id,branch_id,priority,status,
       progress,start_date,due_date,completed_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', $vals);
    $newId = last_id();
    if (isset($vals[2]) && $vals[2] !== '' && (string) $vals[2] !== strtolower((string) $by)) {
        hr_task_assigned_notify((int) $newId, $title, (string) $vals[2]);
    }
    return $newId;
}

/** Assignee alert when a task is created or reassigned. */
function hr_task_assigned_notify(int $taskId, string $title, string $assignee): void {
    $link = '/hr/tasks/' . $taskId;
    if ((int) val('SELECT COUNT(*) FROM hr_notifications WHERE email=? AND type=? AND link=?', [$assignee, 'TASK_ASSIGNED', $link]) > 0) return;
    $msg = 'A task has been assigned to you: "' . $title . '".';
    hr_notify($assignee, 'TASK_ASSIGNED', 'New Task Assigned', $msg, $link);
    hr_notify_email($assignee, 'TASK_ASSIGNED', 'New Task Assigned', $msg, $link);
}

function hr_task_set_status(int $id, string $status, string $by, int $progress = -1): bool {
    $status = strtoupper(trim($status));
    if (!isset(hr_task_statuses()[$status])) return false;
    $t = hr_task($id);
    if ($t === null) return false;
    $completed = $status === 'DONE' ? now_sql() : null;
    if ($progress < 0) {
        $progress = (int) $t['progress'];
        if ($status === 'DONE') $progress = 100;
    }
    q('UPDATE hr_tasks SET status=?, progress=?, completed_at=?, updated_at=? WHERE id=?',
        [$status, max(0, min(100, $progress)), $completed, now_sql(), $id]);
    activity_log(['email' => $by, 'role' => ''], 'HR_TASK_STATUS', 'Task #' . $id . ' -> ' . $status);
    return true;
}

function hr_task_delete(int $id): void {
    q('DELETE FROM hr_task_comments WHERE task_id=?', [$id]);
    q('DELETE FROM hr_tasks WHERE id=?', [$id]);
}

/* ---------------------------------------------------------------- comments ---- */

function hr_task_comments(int $taskId): array {
    return rows('SELECT * FROM hr_task_comments WHERE task_id=? ORDER BY id ASC', [$taskId]);
}

function hr_task_comment_add(int $taskId, string $body, string $by): int {
    $body = hts_block($body);
    if ($body === '' || hr_task($taskId) === null) return 0;
    q('INSERT INTO hr_task_comments (task_id,author_email,body,created_at) VALUES (?,?,?,?)',
        [$taskId, strtolower(trim($by)), $body, now_sql()]);
    return last_id();
}

function hr_task_comment_delete(int $id): void { q('DELETE FROM hr_task_comments WHERE id=?', [$id]); }

function hr_task_comment(?int $id): ?array {
    return $id ? one('SELECT * FROM hr_task_comments WHERE id=?', [$id]) : null;
}

/* ---------------------------------------------------------------- progress ---- */

function hr_task_is_overdue(array $t): bool {
    if (in_array(strtoupper((string) $t['status']), ['DONE', 'CANCELLED'], true)) return false;
    $due = (string) ($t['due_date'] ?? '');
    return $due !== '' && $due < today();
}

function hr_tasks_stats(?string $email = null): array {
    $scope = $email !== null ? ' WHERE assignee_email = ?' : '';
    $p = $email !== null ? [strtolower($email)] : [];
    try {
        $open = (int) val("SELECT COUNT(*) FROM hr_tasks WHERE status IN ('TODO','IN_PROGRESS','REVIEW')" . ($email !== null ? ' AND assignee_email = ?' : ''), $p);
        $overdue = (int) val("SELECT COUNT(*) FROM hr_tasks WHERE status IN ('TODO','IN_PROGRESS','REVIEW') AND due_date<>'' AND due_date < ?" . ($email !== null ? ' AND assignee_email = ?' : ''),
            $email !== null ? [today(), strtolower($email)] : [today()]);
        $dueWeek = (int) val("SELECT COUNT(*) FROM hr_tasks WHERE status IN ('TODO','IN_PROGRESS','REVIEW') AND due_date<>'' AND due_date BETWEEN ? AND ?" . ($email !== null ? ' AND assignee_email = ?' : ''),
            $email !== null ? [today(), date('Y-m-d', strtotime('+7 days')), strtolower($email)] : [today(), date('Y-m-d', strtotime('+7 days'))]);
        $doneMonth = (int) val("SELECT COUNT(*) FROM hr_tasks WHERE status='DONE' AND completed_at LIKE ?" . ($email !== null ? ' AND assignee_email = ?' : ''),
            $email !== null ? [date('Y-m') . '%', strtolower($email)] : [date('Y-m') . '%']);
    } catch (Throwable $e) {
        return ['open' => 0, 'overdue' => 0, 'due_week' => 0, 'done_month' => 0];
    }
    return ['open' => $open, 'overdue' => $overdue, 'due_week' => $dueWeek, 'done_month' => $doneMonth];
}

/** Tasks grouped by board column, for a kanban view. */
function hr_tasks_board(array $filters = []): array {
    $board = [];
    foreach (hr_task_open_statuses() as $s) $board[$s] = [];
    foreach (hr_tasks($filters) as $t) {
        $s = strtoupper((string) $t['status']);
        if (!isset($board[$s])) $board[$s] = [];
        $board[$s][] = $t;
    }
    return $board;
}
