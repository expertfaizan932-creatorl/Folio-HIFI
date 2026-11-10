<?php
/**
 * WorkFolio PHP - HR module data layer: configurable approval workflows.
 *
 * An admin defines a workflow: a named, ordered list of approvers where each
 * step is a role, a specific person, or the requester's reporting manager. Any
 * employee (or HR on their behalf) raises a request against a workflow; the
 * request snapshots the workflow's steps so later edits never rewrite history.
 *
 * Approvers act from a single inbox. Approving the final step closes the
 * request; rejecting any step stops it. Every actor is notified.
 *
 * Additive: this owns only hr_approval_* tables and reads `employees` and
 * `hr_employee_profiles` the same way the rest of HR does.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/hr.php';

/* ------------------------------------------------------------------ helpers ---- */

function happr_text(string $v, int $max): string {
    $v = trim(preg_replace('/\s+/u', ' ', str_replace("\0", '', $v)) ?? $v);
    return mb_substr($v, 0, $max);
}

function happr_block(string $v, int $max = 4000): string {
    $v = str_replace("\0", '', str_replace(["\r\n", "\r"], "\n", $v));
    return mb_substr(trim($v), 0, $max);
}

/* ------------------------------------------------------------------ lookups ---- */

function hr_approval_roles(): array {
    return ['SUPER_ADMIN' => 'Super Admin', 'ADMIN' => 'Admin', 'HR' => 'HR', 'EMPLOYEE' => 'Employee'];
}

function hr_approval_kinds(): array {
    return ['ROLE' => 'Role', 'EMAIL' => 'Specific person', 'MANAGER' => 'Reporting manager'];
}

function hr_approval_request_types(): array {
    return ['GENERAL' => 'General', 'EXPENSE' => 'Expense / Reimbursement', 'PURCHASE' => 'Purchase request',
            'TRAINING' => 'Training request', 'LEAVE' => 'Leave', 'TRAVEL' => 'Travel', 'OTHER' => 'Other'];
}

function hr_approval_status_badge(string $s): string {
    $map = ['PENDING' => 'bg-warning text-dark', 'APPROVED' => 'bg-success',
            'REJECTED' => 'bg-danger', 'CANCELLED' => 'bg-secondary'];
    $s = strtoupper(trim($s));
    return '<span class="badge ' . esc($map[$s] ?? 'bg-secondary') . '">' . esc(ucfirst(strtolower($s))) . '</span>';
}

function hr_approval_request_type_label(string $t): string {
    $t = strtoupper(trim($t));
    return hr_approval_request_types()[$t] ?? $t;
}

/** Human wording for one approver spec. */
function hr_approval_approver_label(string $kind, string $value): string {
    $kind = strtoupper(trim($kind));
    if ($kind === 'EMAIL') return $value;
    if ($kind === 'MANAGER') return 'Reporting manager';
    return (hr_approval_roles()[$value] ?? $value) . ' (role)';
}

/* ---------------------------------------------------------------- workflows ---- */

function hr_approval_workflows(bool $activeOnly = false): array {
    $sql = 'SELECT * FROM hr_approval_workflows';
    if ($activeOnly) $sql .= ' WHERE active=1';
    $sql .= ' ORDER BY name';
    return rows($sql);
}

function hr_approval_workflow(?int $id): ?array {
    return $id ? one('SELECT * FROM hr_approval_workflows WHERE id=?', [$id]) : null;
}

function hr_approval_workflow_steps(int $workflowId): array {
    return rows('SELECT * FROM hr_approval_workflow_steps WHERE workflow_id=? ORDER BY step_no, id', [$workflowId]);
}

function hr_approval_workflow_save(?int $id, array $d, string $by): int {
    $name = happr_text((string) ($d['name'] ?? ''), 160);
    if ($name === '') return 0;
    $desc = happr_text((string) ($d['description'] ?? ''), 400);
    $type = strtoupper(happr_text((string) ($d['request_type'] ?? 'GENERAL'), 60));
    if (!isset(hr_approval_request_types()[$type])) $type = 'GENERAL';
    $active = !empty($d['active']) ? 1 : 0;
    if ($id) {
        q('UPDATE hr_approval_workflows SET name=?, description=?, request_type=?, active=?, updated_by=?, updated_at=?
           WHERE id=?', [$name, $desc, $type, $active, strtolower(trim($by)), now_sql(), $id]);
        return $id;
    }
    q('INSERT INTO hr_approval_workflows (name,description,request_type,active,created_by,created_at,updated_at)
       VALUES (?,?,?,?,?,?,?)', [$name, $desc, $type, $active, strtolower(trim($by)), now_sql(), now_sql()]);
    return last_id();
}

function hr_approval_workflow_delete(int $id): void {
    q('DELETE FROM hr_approval_workflow_steps WHERE workflow_id=?', [$id]);
    q('DELETE FROM hr_approval_workflows WHERE id=?', [$id]);
}

/**
 * Upsert one step. Steps are ordered by step_no; a missing id appends. Returns
 * the step id, or 0 when the approver spec is unusable.
 */
function hr_approval_step_save(int $workflowId, ?int $id, array $d): int {
    if (hr_approval_workflow($workflowId) === null) return 0;
    $kind = strtoupper(happr_text((string) ($d['approver_kind'] ?? 'ROLE'), 20));
    if (!isset(hr_approval_kinds()[$kind])) $kind = 'ROLE';
    $value = happr_text((string) ($d['approver_value'] ?? ''), 190);
    if ($kind === 'ROLE') {
        $value = strtoupper($value);
        if (!isset(hr_approval_roles()[$value])) $value = 'HR';
    } elseif ($kind === 'EMAIL') {
        $value = strtolower($value);
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) return 0;
    } else { /* MANAGER */
        $value = '';
    }
    $label = happr_text((string) ($d['label'] ?? ''), 160);
    $stepNo = max(1, (int) ($d['step_no'] ?? (hr_approval_next_step_no($workflowId))));
    if ($id) {
        q('UPDATE hr_approval_workflow_steps SET step_no=?, approver_kind=?, approver_value=?, label=? WHERE id=? AND workflow_id=?',
          [$stepNo, $kind, $value, $label, $id, $workflowId]);
        return $id;
    }
    q('INSERT INTO hr_approval_workflow_steps (workflow_id,step_no,approver_kind,approver_value,label,created_at)
       VALUES (?,?,?,?,?,?)', [$workflowId, $stepNo, $kind, $value, $label, now_sql()]);
    return last_id();
}

function hr_approval_next_step_no(int $workflowId): int {
    return 1 + (int) val('SELECT COALESCE(MAX(step_no),0) FROM hr_approval_workflow_steps WHERE workflow_id=?', [$workflowId]);
}

function hr_approval_step(int $id): ?array {
    return one('SELECT * FROM hr_approval_workflow_steps WHERE id=?', [$id]);
}

function hr_approval_step_delete(int $id): void {
    q('DELETE FROM hr_approval_workflow_steps WHERE id=?', [$id]);
}

/* --------------------------------------------------------------- requests ---- */

/**
 * Raise a request against a workflow. Snapshots the workflow's steps, sets the
 * first step live and notifies its approvers. Returns the request id, or 0 when
 * the workflow is missing/empty or the title is blank.
 */
function hr_approval_request_create(?int $workflowId, array $d, string $by): int {
    $title = happr_text((string) ($d['title'] ?? ''), 200);
    if ($title === '') return 0;
    $wf = hr_approval_workflow($workflowId);
    $steps = $wf !== null ? hr_approval_workflow_steps((int) $wf['id']) : [];
    $requester = strtolower(trim($by));
    if ($steps === [] && $requester !== '') {
        /* No workflow chosen: fall back to a single HR step so a request can
           always be raised and routed. */
        $steps = [['step_no' => 1, 'approver_kind' => 'ROLE', 'approver_value' => 'HR', 'label' => 'HR review']];
    }
    if ($steps === []) return 0;
    $type = $wf !== null ? (string) $wf['request_type'] : strtoupper(happr_text((string) ($d['request_type'] ?? 'GENERAL'), 60));
    if (!isset(hr_approval_request_types()[$type])) $type = 'GENERAL';
    q('INSERT INTO hr_approval_requests
       (workflow_id,request_type,title,body,amount,requested_by,status,current_step,total_steps,created_at,updated_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
        $wf !== null ? (int) $wf['id'] : null, $type, $title,
        happr_block((string) ($d['body'] ?? '')),
        happr_text((string) ($d['amount'] ?? ''), 60),
        $requester, 'PENDING', 1, count($steps), now_sql(), now_sql(),
    ]);
    $rid = last_id();
    $no = 0;
    foreach ($steps as $s) {
        $no++;
        q('INSERT INTO hr_approval_steps (request_id,step_no,approver_kind,approver_value,label,status,created_at)
           VALUES (?,?,?,?,?,?,?)', [
            $rid, $no, (string) $s['approver_kind'], (string) $s['approver_value'],
            (string) ($s['label'] ?? ''), 'PENDING', now_sql(),
        ]);
    }
    hr_approval_notify_step($rid, 1, $wf !== null ? (string) $wf['name'] : $title);
    return $rid;
}

function hr_approval_request(?int $id): ?array {
    return $id ? one('SELECT * FROM hr_approval_requests WHERE id=?', [$id]) : null;
}

function hr_approval_request_steps(int $requestId): array {
    return rows('SELECT * FROM hr_approval_steps WHERE request_id=? ORDER BY step_no, id', [$requestId]);
}

function hr_approval_requests(array $filters = []): array {
    $sql = 'SELECT * FROM hr_approval_requests';
    $where = []; $p = [];
    if (!empty($filters['status'])) { $where[] = 'status = ?'; $p[] = strtoupper((string) $filters['status']); }
    if (!empty($filters['requested_by'])) { $where[] = 'requested_by = ?'; $p[] = strtolower((string) $filters['requested_by']); }
    if (!empty($filters['request_type'])) { $where[] = 'request_type = ?'; $p[] = strtoupper((string) $filters['request_type']); }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY id DESC';
    if (!empty($filters['limit'])) $sql .= ' LIMIT ' . (int) $filters['limit'];
    return rows($sql, $p);
}

function hr_approval_stats(): array {
    $out = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'workflows' => 0];
    foreach (rows('SELECT status, COUNT(*) AS n FROM hr_approval_requests GROUP BY status') as $r) {
        $k = strtolower((string) $r['status']);
        if (isset($out[$k])) $out[$k] = (int) $r['n'];
    }
    $out['workflows'] = (int) val('SELECT COUNT(*) FROM hr_approval_workflows WHERE active=1');
    return $out;
}

/** Emails that can act on a step. MANAGER resolves through the requester's profile. */
function hr_approval_step_approver_emails(array $step, string $requesterEmail): array {
    $kind = strtoupper((string) $step['approver_kind']);
    if ($kind === 'EMAIL') {
        $e = strtolower(trim((string) $step['approver_value']));
        return $e === '' ? [] : [$e];
    }
    if ($kind === 'MANAGER') {
        $p = one('SELECT manager_email FROM hr_employee_profiles WHERE email=?', [strtolower(trim($requesterEmail))]);
        $m = $p !== null ? strtolower(trim((string) $p['manager_email'])) : '';
        return $m === '' ? [] : [$m];
    }
    $role = strtoupper(trim((string) $step['approver_value']));
    $out = [];
    foreach (rows('SELECT email FROM employees WHERE `role`=?', [$role]) as $e) $out[] = strtolower((string) $e['email']);
    return $out;
}

/** Notify the approvers of a given step (with dedupe per request+step). */
function hr_approval_notify_step(int $requestId, int $stepNo, string $title): void {
    $r = hr_approval_request($requestId);
    if ($r === null) return;
    $step = one('SELECT * FROM hr_approval_steps WHERE request_id=? AND step_no=?', [$requestId, $stepNo]);
    if ($step === null) return;
    $link = '/hr/approvals/' . $requestId;
    $body = strtoupper((string) $r['requested_by']) . ' raised "' . (string) $r['title'] . '" and it needs your approval.';
    foreach (hr_approval_step_approver_emails($step, (string) $r['requested_by']) as $email) {
        if ((int) val('SELECT COUNT(*) FROM hr_notifications WHERE email=? AND type=? AND link=?',
                [$email, 'APPROVAL_NEEDED', $link]) > 0) continue;
        hr_notify($email, 'APPROVAL_NEEDED', 'Approval needed', $body, $link, (string) $r['requested_by']);
        hr_notify_email($email, 'APPROVAL_NEEDED', 'Approval needed', $body, $link);
    }
}

/**
 * Approve or reject the current step. Approving advances to the next step or,
 * at the end, closes the request APPROVED. Rejecting stops it REJECTED. Returns
 * an error string, or '' on success.
 */
function hr_approval_decide(int $id, string $action, string $by, string $note): string {
    $r = hr_approval_request($id);
    if ($r === null) return 'Request not found.';
    if (strtoupper((string) $r['status']) !== 'PENDING') return 'This request is already closed.';
    $by = strtolower(trim($by));
    $stepNo = (int) $r['current_step'];
    $step = one('SELECT * FROM hr_approval_steps WHERE request_id=? AND step_no=?', [$id, $stepNo]);
    if ($step === null) return 'The current step is missing.';
    if (!in_array($by, hr_approval_step_approver_emails($step, (string) $r['requested_by']), true)) {
        return 'You are not the approver for this step.';
    }
    $action = strtolower($action);
    $note = happr_text($note, 400);
    if ($action === 'reject') {
        q('UPDATE hr_approval_steps SET status=?, decided_by=?, decided_at=?, note=? WHERE id=?',
          ['REJECTED', $by, now_sql(), $note, (int) $step['id']]);
        q('UPDATE hr_approval_requests SET status=?, decided_by=?, decided_at=?, decision_note=?, updated_at=? WHERE id=?',
          ['REJECTED', $by, now_sql(), $note, now_sql(), $id]);
        hr_approval_notify_requester($r, false, $by, $note);
        return '';
    }
    if ($action !== 'approve') return 'Unknown action.';
    q('UPDATE hr_approval_steps SET status=?, decided_by=?, decided_at=?, note=? WHERE id=?',
      ['APPROVED', $by, now_sql(), $note, (int) $step['id']]);
    $total = (int) $r['total_steps'];
    if ($stepNo >= $total) {
        q('UPDATE hr_approval_requests SET status=?, decided_by=?, decided_at=?, decision_note=?, updated_at=? WHERE id=?',
          ['APPROVED', $by, now_sql(), $note, now_sql(), $id]);
        hr_approval_notify_requester($r, true, $by, $note);
        return '';
    }
    $next = $stepNo + 1;
    q('UPDATE hr_approval_requests SET current_step=?, updated_at=? WHERE id=?', [$next, now_sql(), $id]);
    hr_approval_notify_step($id, $next, (string) $r['title']);
    return '';
}

function hr_approval_notify_requester(array $r, bool $approved, string $by, string $note): void {
    $email = strtolower((string) $r['requested_by']);
    $link = '/hr/approvals/' . (int) $r['id'];
    $title = $approved ? 'Request approved' : 'Request rejected';
    $body = 'Your request "' . (string) $r['title'] . '" was ' . ($approved ? 'approved' : 'rejected') . ' by ' . $by
        . ($note !== '' ? ' (' . $note . ')' : '') . '.';
    hr_notify($email, $approved ? 'APPROVAL_APPROVED' : 'APPROVAL_REJECTED', $title, $body, $link, $by);
    hr_notify_email($email, $approved ? 'APPROVAL_APPROVED' : 'APPROVAL_REJECTED', $title, $body, $link);
}

function hr_approval_cancel(int $id, array $u): string {
    $r = hr_approval_request($id);
    if ($r === null) return 'Request not found.';
    if (strtoupper((string) $r['status']) !== 'PENDING') return 'This request is already closed.';
    $isOwner = strtolower((string) $r['requested_by']) === strtolower((string) $u['email']);
    if (!$isOwner && !hr_is_manager($u)) return 'You cannot cancel this request.';
    q('UPDATE hr_approval_requests SET status=?, updated_at=? WHERE id=?', ['CANCELLED', now_sql(), $id]);
    return '';
}

/**
 * Requests PENDING on a step the user can act on. Returns rows decorated with
 * 'step_label' and 'step_no'.
 */
function hr_approval_pending_for(array $u, int $limit = 100): array {
    $email = strtolower(trim((string) $u['email']));
    $role = strtoupper((string) ($u['role'] ?? ''));
    $out = [];
    foreach (hr_approval_requests(['status' => 'PENDING', 'limit' => 500]) as $r) {
        $step = one('SELECT * FROM hr_approval_steps WHERE request_id=? AND step_no=?',
            [(int) $r['id'], (int) $r['current_step']]);
        if ($step === null) continue;
        $approvers = hr_approval_step_approver_emails($step, (string) $r['requested_by']);
        $kind = strtoupper((string) $step['approver_kind']);
        $match = in_array($email, $approvers, true)
            || ($kind === 'ROLE' && $role !== '' && strcasecmp($role, (string) $step['approver_value']) === 0);
        if ($match) {
            $r['step_label'] = hr_approval_approver_label($kind, (string) $step['approver_value']);
            $out[] = $r;
            if (count($out) >= $limit) break;
        }
    }
    return $out;
}

/** Requests raised by the user, newest first. */
function hr_approval_mine(string $email, int $limit = 50): array {
    return hr_approval_requests(['requested_by' => strtolower(trim($email)), 'limit' => $limit]);
}
