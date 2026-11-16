<?php
/**
 * WorkFolio PHP - HR module data layer, Phase 2: Recruitment / ATS.
 *
 * Additive like hr.php: it owns the hr_job_openings / hr_application_openings /
 * hr_interviews / hr_offers tables and only reads the existing hiring-intake
 * table (employee_form_submissions) as the candidate pool. Nothing here changes
 * how that intake form behaves; the ATS is a management layer on top of it.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/hr.php';              /* hr_int_or_null() */
require_once __DIR__ . '/employee_form.php';   /* ef_find / ef_all / ef_statuses */

/* ---------------------------------------------------------------- lookups ---- */

/** Vacancy lifecycle. This list is the only source of a legal opening status. */
function hr_opening_statuses(): array {
    return ['DRAFT' => 'Draft', 'OPEN' => 'Open', 'ON_HOLD' => 'On Hold', 'CLOSED' => 'Closed'];
}

function hr_opening_status_badge(string $s): string {
    $map = ['DRAFT' => 'bg-secondary', 'OPEN' => 'bg-success', 'ON_HOLD' => 'bg-warning text-dark',
            'CLOSED' => 'bg-dark'];
    $labels = hr_opening_statuses();
    $s = strtoupper(trim($s)) ?: 'DRAFT';
    return '<span class="badge ' . esc($map[$s] ?? 'bg-secondary') . '">' . esc($labels[$s] ?? $s) . '</span>';
}

function hr_interview_stages(): array {
    return ['SCHEDULED' => 'Scheduled', 'DONE' => 'Completed', 'CANCELLED' => 'Cancelled', 'NO_SHOW' => 'No Show'];
}

function hr_interview_stage_badge(string $s): string {
    $map = ['SCHEDULED' => 'bg-primary', 'DONE' => 'bg-success', 'CANCELLED' => 'bg-secondary', 'NO_SHOW' => 'bg-danger'];
    $labels = hr_interview_stages();
    $s = strtoupper(trim($s)) ?: 'SCHEDULED';
    return '<span class="badge ' . esc($map[$s] ?? 'bg-secondary') . '">' . esc($labels[$s] ?? $s) . '</span>';
}

function hr_interview_modes(): array { return ['ONSITE', 'PHONE', 'VIDEO']; }

/** Where a candidate came from. Free-form is allowed; these are the common ones. */
function hr_candidate_sources(): array {
    return ['LINKEDIN' => 'LinkedIn', 'REFERRAL' => 'Referral', 'WEBSITE' => 'Company Website',
            'AGENCY' => 'Agency', 'WALK_IN' => 'Walk-in', 'CAMPUS' => 'Campus', 'OTHER' => 'Other'];
}

function hr_candidate_source_label(string $s): string {
    $s = strtoupper(trim($s));
    if ($s === '') return '-';
    return hr_candidate_sources()[$s] ?? $s;
}

function hr_comm_channels(): array {
    return ['NOTE' => 'Note', 'EMAIL' => 'Email', 'CALL' => 'Call',
            'SMS' => 'SMS', 'WHATSAPP' => 'WhatsApp', 'MEETING' => 'Meeting'];
}

function hr_comm_directions(): array {
    return ['OUT' => 'Outbound', 'IN' => 'Inbound'];
}

function hr_offer_statuses(): array {
    return ['DRAFT' => 'Draft', 'SENT' => 'Sent', 'ACCEPTED' => 'Accepted', 'DECLINED' => 'Declined', 'WITHDRAWN' => 'Withdrawn'];
}

function hr_offer_status_badge(string $s): string {
    $map = ['DRAFT' => 'bg-secondary', 'SENT' => 'bg-primary', 'ACCEPTED' => 'bg-success',
            'DECLINED' => 'bg-danger', 'WITHDRAWN' => 'bg-dark'];
    $labels = hr_offer_statuses();
    $s = strtoupper(trim($s)) ?: 'DRAFT';
    return '<span class="badge ' . esc($map[$s] ?? 'bg-secondary') . '">' . esc($labels[$s] ?? $s) . '</span>';
}

function hr_clean_text(string $v, int $max): string {
    $v = str_replace("\0", '', $v);
    $v = preg_replace('/\s+/u', ' ', trim($v)) ?? trim($v);
    return substr($v, 0, $max);
}

function hr_clean_block(string $v, int $max = 5000): string {
    $v = str_replace("\0", '', $v);
    $v = preg_replace("/\r\n?/", "\n", trim($v)) ?? trim($v);
    return substr($v, 0, $max);
}

/* ----------------------------------------------------------------- vacancies ---- */

/** id => name maps for the org lookups, reused by the ATS screens. */
function hr_openings_all(array $filters = []): array {
    $sql = 'SELECT o.*, d.name AS department_name, b.name AS branch_name
            FROM hr_job_openings o
            LEFT JOIN hr_departments d ON d.id = o.department_id
            LEFT JOIN hr_branches b ON b.id = o.branch_id';
    $where = []; $p = [];
    if (!empty($filters['status'])) { $where[] = 'o.status = ?'; $p[] = strtoupper((string) $filters['status']); }
    if (!empty($filters['department_id'])) { $where[] = 'o.department_id = ?'; $p[] = (int) $filters['department_id']; }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY o.id DESC';
    return rows($sql, $p);
}

function hr_opening(?int $id): ?array {
    return $id ? one('SELECT * FROM hr_job_openings WHERE id=?', [$id]) : null;
}

function hr_opening_save(?int $id, array $d, string $by): int {
    $title = hr_clean_text((string) ($d['title'] ?? ''), 160);
    if ($title === '') return 0;
    $status = array_key_exists(strtoupper(trim((string) ($d['status'] ?? 'DRAFT'))), hr_opening_statuses())
        ? strtoupper(trim((string) $d['status'])) : 'DRAFT';
    $vals = [
        $title,
        hr_int_or_null($d['department_id'] ?? null),
        hr_int_or_null($d['branch_id'] ?? null),
        hr_clean_text((string) ($d['employment_type'] ?? ''), 60),
        hr_clean_text((string) ($d['work_location'] ?? ''), 160),
        max(1, (int) ($d['vacancies'] ?? 1)),
        hr_clean_text((string) ($d['salary_min'] ?? ''), 60),
        hr_clean_text((string) ($d['salary_max'] ?? ''), 60),
        hr_clean_block((string) ($d['description'] ?? '')),
        hr_clean_block((string) ($d['requirements'] ?? '')),
        $status,
        hr_clean_text((string) ($d['posted_date'] ?? ''), 20),
        hr_clean_text((string) ($d['closing_date'] ?? ''), 20),
        hr_clean_text((string) ($d['hiring_manager'] ?? ''), 190),
    ];
    if ($id) {
        $vals[] = now_sql();
        $vals[] = $id;
        q('UPDATE hr_job_openings SET title=?, department_id=?, branch_id=?, employment_type=?, work_location=?,
           vacancies=?, salary_min=?, salary_max=?, description=?, requirements=?, status=?, posted_date=?,
           closing_date=?, hiring_manager=?, updated_at=? WHERE id=?', $vals);
        return $id;
    }
    $vals[] = strtolower(trim($by));
    $vals[] = now_sql();
    $vals[] = now_sql();
    q('INSERT INTO hr_job_openings (title,department_id,branch_id,employment_type,work_location,vacancies,
       salary_min,salary_max,description,requirements,status,posted_date,closing_date,hiring_manager,
       created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', $vals);
    return last_id();
}

function hr_opening_delete(int $id): void {
    q('DELETE FROM hr_application_openings WHERE opening_id=?', [$id]);
    q('UPDATE hr_interviews SET opening_id=NULL WHERE opening_id=?', [$id]);
    q('UPDATE hr_offers SET opening_id=NULL WHERE opening_id=?', [$id]);
    q('DELETE FROM hr_job_openings WHERE id=?', [$id]);
}

function hr_opening_set_status(int $id, string $status, string $by): bool {
    $status = strtoupper(trim($status));
    if (!isset(hr_opening_statuses()[$status])) return false;
    if (hr_opening($id) === null) return false;
    q('UPDATE hr_job_openings SET status=?, updated_at=? WHERE id=?', [$status, now_sql(), $id]);
    activity_log(['email' => $by, 'role' => ''], 'HR_OPENING_STATUS', 'Vacancy #' . $id . ' -> ' . $status);
    return true;
}

function hr_openings_count_open(): int {
    try { return (int) val("SELECT COUNT(*) FROM hr_job_openings WHERE status='OPEN'"); }
    catch (Throwable $e) { return 0; }
}

/** How many candidates are attached to each opening, as opening_id => count. */
function hr_opening_application_counts(): array {
    $out = [];
    foreach (rows('SELECT opening_id, COUNT(*) AS n FROM hr_application_openings WHERE opening_id IS NOT NULL GROUP BY opening_id') as $r) {
        $out[(int) $r['opening_id']] = (int) $r['n'];
    }
    return $out;
}

/* ----------------------------------------------------------- candidate pool ---- */

/** The ATS link row for one application, or null when it is not attached yet. */
function hr_application_link(int $submissionId): ?array {
    return one('SELECT * FROM hr_application_openings WHERE submission_id=?', [$submissionId]);
}

function hr_application_link_save(int $submissionId, ?int $openingId, string $by): void {
    if (ef_find($submissionId) === null) return;
    $existing = hr_application_link($submissionId);
    if ($existing === null) {
        q('INSERT INTO hr_application_openings (submission_id,opening_id,linked_by,linked_at) VALUES (?,?,?,?)',
          [$submissionId, $openingId, strtolower(trim($by)), now_sql()]);
    } else {
        q('UPDATE hr_application_openings SET opening_id=?, linked_by=?, linked_at=? WHERE submission_id=?',
          [$openingId, strtolower(trim($by)), now_sql(), $submissionId]);
    }
}

/** Save the candidate's sourcing details, creating the link row if needed. */
function hr_candidate_meta_save(int $submissionId, array $d, string $by): bool {
    if (ef_find($submissionId) === null) return false;
    $source = strtoupper(hr_clean_text((string) ($d['source'] ?? ''), 60));
    $recruiter = strtolower(hr_clean_text((string) ($d['recruiter_email'] ?? ''), 190));
    if ($recruiter !== '' && !filter_var($recruiter, FILTER_VALIDATE_EMAIL)) $recruiter = '';
    $existing = hr_application_link($submissionId);
    if ($existing === null) {
        q('INSERT INTO hr_application_openings (submission_id,opening_id,source,recruiter_email,linked_by,linked_at)
           VALUES (?,?,?,?,?,?)',
          [$submissionId, null, $source, $recruiter, strtolower(trim($by)), now_sql()]);
    } else {
        q('UPDATE hr_application_openings SET source=?, recruiter_email=?, linked_by=?, linked_at=? WHERE submission_id=?',
          [$source, $recruiter, strtolower(trim($by)), now_sql(), $submissionId]);
    }
    return true;
}

/* ------------------------------------------------------ candidate comms log ---- */

function hr_candidate_comms(int $submissionId): array {
    return rows('SELECT * FROM hr_candidate_comms WHERE submission_id=? ORDER BY id DESC', [$submissionId]);
}

function hr_candidate_comm_add(int $submissionId, array $d, string $by): int {
    if (ef_find($submissionId) === null) return 0;
    $channel = strtoupper(hr_clean_text((string) ($d['channel'] ?? 'NOTE'), 30));
    if (!isset(hr_comm_channels()[$channel])) $channel = 'NOTE';
    $direction = strtoupper(hr_clean_text((string) ($d['direction'] ?? 'OUT'), 10));
    if (!isset(hr_comm_directions()[$direction])) $direction = 'OUT';
    $summary = hr_clean_text((string) ($d['summary'] ?? ''), 200);
    $body = hr_clean_block((string) ($d['body'] ?? ''));
    if ($summary === '' && $body === '') return 0;
    q('INSERT INTO hr_candidate_comms (submission_id,channel,direction,summary,body,logged_by,created_at)
       VALUES (?,?,?,?,?,?,?)',
      [$submissionId, $channel, $direction, $summary, $body, strtolower(trim($by)), now_sql()]);
    return last_id();
}

function hr_candidate_comm_delete(int $id): void {
    q('DELETE FROM hr_candidate_comms WHERE id=?', [$id]);
}

/**
 * Candidates for the board: every application, optionally narrowed to one
 * vacancy and/or pipeline status, with its opening name joined in.
 */
function hr_candidates(array $filters = []): array {
    $sql = 'SELECT s.*, l.opening_id, l.source, l.recruiter_email,
                   o.title AS opening_title, o.status AS opening_status
            FROM employee_form_submissions s
            LEFT JOIN hr_application_openings l ON l.submission_id = s.id
            LEFT JOIN hr_job_openings o ON o.id = l.opening_id';
    $where = []; $p = [];
    if (!empty($filters['opening_id'])) { $where[] = 'l.opening_id = ?'; $p[] = (int) $filters['opening_id']; }
    if (!empty($filters['unassigned'])) { $where[] = 'l.opening_id IS NULL'; }
    if (!empty($filters['status'])) { $where[] = 's.status = ?'; $p[] = strtoupper((string) $filters['status']); }
    if (!empty($filters['source'])) { $where[] = 'l.source = ?'; $p[] = strtoupper((string) $filters['source']); }
    if (!empty($filters['recruiter'])) { $where[] = 'l.recruiter_email = ?'; $p[] = strtolower((string) $filters['recruiter']); }
    if (!empty($filters['q'])) {
        $where[] = '(s.full_name LIKE ? OR s.email LIKE ? OR s.position_title LIKE ?)';
        $like = '%' . (string) $filters['q'] . '%';
        array_push($p, $like, $like, $like);
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY s.id DESC';
    if (!empty($filters['limit'])) $sql .= ' LIMIT ' . (int) $filters['limit'];
    return rows($sql, $p);
}

/** Distinct recruiters currently assigned across applications (email => count). */
function hr_recruiters_in_use(): array {
    $out = [];
    foreach (rows("SELECT recruiter_email, COUNT(*) AS n FROM hr_application_openings
                   WHERE recruiter_email IS NOT NULL AND recruiter_email <> '' GROUP BY recruiter_email
                   ORDER BY recruiter_email ASC") as $r) {
        $out[(string) $r['recruiter_email']] = (int) $r['n'];
    }
    return $out;
}

/** Pipeline counts by the intake status, used by the board headers. */
function hr_candidate_stage_counts(array $filters = []): array {
    $out = [];
    foreach (hr_candidates($filters) as $c) {
        $st = strtoupper((string) ($c['status'] ?? 'SUBMITTED'));
        $out[$st] = ($out[$st] ?? 0) + 1;
    }
    return $out;
}

/* ---------------------------------------------------------------- interviews ---- */

function hr_interviews_for(int $submissionId): array {
    return rows('SELECT * FROM hr_interviews WHERE submission_id=? ORDER BY scheduled_at ASC, id ASC', [$submissionId]);
}

/** Upcoming or filtered interviews, joined to the candidate name. */
function hr_interviews_all(array $filters = []): array {
    $sql = 'SELECT i.*, s.full_name, s.email, s.position_title
            FROM hr_interviews i LEFT JOIN employee_form_submissions s ON s.id = i.submission_id';
    $where = []; $p = [];
    if (!empty($filters['submission_id'])) { $where[] = 'i.submission_id = ?'; $p[] = (int) $filters['submission_id']; }
    if (!empty($filters['stage'])) { $where[] = 'i.stage = ?'; $p[] = strtoupper((string) $filters['stage']); }
    if (!empty($filters['upcoming'])) { $where[] = 'i.scheduled_at >= ?'; $p[] = now_sql(); }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY i.scheduled_at ASC, i.id ASC';
    if (!empty($filters['limit'])) $sql .= ' LIMIT ' . (int) $filters['limit'];
    return rows($sql, $p);
}

function hr_interview(?int $id): ?array {
    return $id ? one('SELECT * FROM hr_interviews WHERE id=?', [$id]) : null;
}

function hr_interview_save(?int $id, int $submissionId, ?int $openingId, array $d, string $by): int {
    $stage = strtoupper(trim((string) ($d['stage'] ?? 'SCHEDULED')));
    if (!isset(hr_interview_stages()[$stage])) $stage = 'SCHEDULED';
    $mode = strtoupper(trim((string) ($d['mode'] ?? 'ONSITE')));
    if (!in_array($mode, hr_interview_modes(), true)) $mode = 'ONSITE';
    $scheduled = hr_clean_text((string) ($d['scheduled_at'] ?? ''), 30);
    $scheduled = $scheduled !== '' ? str_replace('T', ' ', $scheduled) : '';
    if (strlen($scheduled) === 16) $scheduled .= ':00';
    $vals = [
        $submissionId, $openingId,
        max(1, (int) ($d['round_no'] ?? 1)),
        $stage, $mode, $scheduled,
        hr_clean_text((string) ($d['interviewer'] ?? ''), 190),
        hr_clean_text((string) ($d['location'] ?? ''), 300),
        hr_clean_block((string) ($d['notes'] ?? '')),
        hr_clean_block((string) ($d['feedback'] ?? '')),
        max(0, min(5, (int) ($d['rating'] ?? 0))),
    ];
    if ($id) {
        $vals[] = now_sql();
        $vals[] = $id;
        q('UPDATE hr_interviews SET submission_id=?, opening_id=?, round_no=?, stage=?, mode=?, scheduled_at=?,
           interviewer=?, location=?, notes=?, feedback=?, rating=?, updated_at=? WHERE id=?', $vals);
        return $id;
    }
    $vals[] = strtolower(trim($by));
    $vals[] = now_sql();
    $vals[] = now_sql();
    q('INSERT INTO hr_interviews (submission_id,opening_id,round_no,stage,mode,scheduled_at,interviewer,location,
       notes,feedback,rating,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', $vals);
    return last_id();
}

function hr_interview_update(int $id, string $stage, string $feedback, int $rating, string $by): bool {
    $stage = strtoupper(trim($stage));
    if (!isset(hr_interview_stages()[$stage])) return false;
    if (hr_interview($id) === null) return false;
    q('UPDATE hr_interviews SET stage=?, feedback=?, rating=?, updated_at=? WHERE id=?',
      [$stage, hr_clean_block($feedback), max(0, min(5, $rating)), now_sql(), $id]);
    return true;
}

function hr_interview_delete(int $id): void { q('DELETE FROM hr_interviews WHERE id=?', [$id]); }

/* -------------------------------------------------------------------- offers ---- */

function hr_offer(int $submissionId): ?array {
    return one('SELECT * FROM hr_offers WHERE submission_id=?', [$submissionId]);
}

function hr_offer_save(int $submissionId, ?int $openingId, array $d, string $by): int {
    $status = strtoupper(trim((string) ($d['status'] ?? 'DRAFT')));
    if (!isset(hr_offer_statuses()[$status])) $status = 'DRAFT';
    $vals = [
        $submissionId, $openingId,
        hr_clean_text((string) ($d['position_title'] ?? ''), 160),
        hr_clean_text((string) ($d['offered_salary'] ?? ''), 60),
        hr_clean_text((string) ($d['joining_date'] ?? ''), 20),
        $status,
        hr_clean_block((string) ($d['notes'] ?? '')),
    ];
    $existing = hr_offer($submissionId);
    if ($existing !== null) {
        $vals[] = now_sql();
        $vals[] = $submissionId;
        q('UPDATE hr_offers SET submission_id=?, opening_id=?, position_title=?, offered_salary=?, joining_date=?,
           status=?, notes=?, updated_at=? WHERE submission_id=?', $vals);
        return (int) $existing['id'];
    }
    $vals[] = strtolower(trim($by));
    $vals[] = now_sql();
    $vals[] = now_sql();
    q('INSERT INTO hr_offers (submission_id,opening_id,position_title,offered_salary,joining_date,status,notes,
       created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)', $vals);
    return last_id();
}
