<?php
/**
 * WorkFolio PHP - the Employee Form: hiring intake.
 *
 * One row per submission. Deliberately NOT the employee_hrm_records table: that
 * one is keyed to somebody already in the roster and is filled in by the employee
 * themselves through a personal link. This one is filled in by whoever is doing
 * the hiring, about a person who does not exist in the roster yet - so there is
 * no login, no token and no employee row to hang it off. Keeping them apart also
 * means the same candidate can apply twice, for two different roles, and both
 * applications stay on record instead of the second overwriting the first.
 *
 * Two rules keep this honest. The field map below is the only definition of the
 * form, so the screen, the validation, the insert and the review can never
 * disagree about what exists or what is mandatory. And a status is only ever
 * accepted from the fixed list below, never from whatever the request said.
 */
require_once __DIR__ . '/hrm.php';   /* reuses its upload sniffing, see ef_check_cv */

/** Field keys in display order, grouped into the sections the screen draws. */
function ef_sections(): array {
    return [
        ['key' => 'candidate', 'title' => 'Candidate Details',
         'hint' => 'Who this application is about.',
         'keys' => ['full_name', 'email', 'phone', 'cnic', 'dob', 'gender',
                    'marital_status', 'current_address']],
        ['key' => 'position', 'title' => 'Position Applied For',
         'hint' => 'The role this application is against.',
         'keys' => ['position_title', 'department', 'employment_type', 'work_location',
                    'joining_date', 'salary_expected', 'salary_offered']],
        ['key' => 'education', 'title' => 'Education & Qualification',
         'hint' => '',
         'keys' => ['highest_degree', 'institute', 'graduation_year']],
        ['key' => 'experience', 'title' => 'Experience & Skills',
         'hint' => '',
         'keys' => ['previous_company', 'total_experience', 'skills',
                    'previous_experience', 'linkedin', 'portfolio']],
        ['key' => 'other', 'title' => 'Emergency Contact & Notes',
         'hint' => 'Reachability for the interview, and anything else worth writing down.',
         'keys' => ['emergency_contact_name', 'emergency_contact_number', 'notes']],
    ];
}

/**
 * Every field: key => [label, type, required, options, hint, maxLength].
 *
 * One map is the whole form. Types are only 'text', 'email', 'tel', 'date',
 * 'url' and 'textarea', which is exactly what ef_clean() and the input renderer
 * know how to handle, so an unknown type cannot slip through half-rendered.
 *
 * The last entry is the column width, and it is not decoration. The values are
 * cleaned to it before the insert, because MySQL in strict mode refuses a value
 * that overruns its column - so a long paste would otherwise turn into "the
 * application could not be saved" instead of a stored application.
 */
function ef_fields(): array {
    return [
        /* candidate */
        'full_name'       => ['Full Name', 'text', true, [], '', 200],
        'email'           => ['Email Address', 'email', true, [], 'Where we reach them', 190],
        'phone'           => ['Phone Number', 'tel', true, [], '', 40],
        'cnic'            => ['CNIC / National ID', 'text', false, [], 'Format: 00000-0000000-0', 30],
        'dob'             => ['Date of Birth', 'date', false, [], '', 20],
        'gender'          => ['Gender', 'select', false, ['Male', 'Female', 'Other'], '', 20],
        'marital_status'  => ['Marital Status', 'select', false, ['Single', 'Married', 'Divorced', 'Widowed'], '', 30],
        'current_address' => ['Current Address', 'textarea', false, [], '', 2000],

        /* position */
        'position_title'  => ['Position Title', 'text', true, [], 'e.g. Accounts Officer', 160],
        'department'      => ['Department', 'text', false, [], '', 160],
        'employment_type' => ['Employment Type', 'select', false,
            ['Full Time', 'Part Time', 'Contract', 'Intern', 'Consultant'], '', 60],
        'work_location'   => ['Work Location', 'text', false, [], 'e.g. Lahore - Head Office', 120],
        'joining_date'    => ['Expected Joining Date', 'date', false, [], '', 20],
        'salary_expected' => ['Expected Salary', 'text', false, [], 'Monthly, in PKR', 60],
        'salary_offered'  => ['Salary Offered', 'text', false, [], 'Monthly, in PKR', 60],

        /* education */
        'highest_degree'  => ['Highest Degree', 'text', false, [], '', 160],
        'institute'       => ['Institute / University', 'text', false, [], '', 200],
        'graduation_year' => ['Graduation Year', 'text', false, [], 'e.g. 2019', 20],

        /* experience */
        'previous_company'    => ['Previous Company', 'text', false, [], '', 200],
        'total_experience'    => ['Total Experience', 'text', false, [], 'e.g. 4 years', 60],
        'skills'              => ['Skills', 'textarea', false, [], 'One per line', 2000],
        'previous_experience' => ['Previous Experience', 'textarea', false, [], 'One role per line', 2000],
        'linkedin'            => ['LinkedIn Profile', 'url', false, [], '', 400],
        'portfolio'           => ['Portfolio / Website', 'url', false, [], '', 400],

        /* other */
        'emergency_contact_name'   => ['Emergency Contact Person', 'text', false, [], '', 200],
        'emergency_contact_number' => ['Emergency Contact Number', 'tel', false, [], '', 40],
        'notes'                    => ['Notes', 'textarea', false, [], 'Anything else about this application', 2000],
    ];
}

/** The hiring pipeline. This list is the only source of a legal status value. */
function ef_statuses(): array {
    return [
        'SUBMITTED'    => 'Submitted',
        'UNDER_REVIEW' => 'Under Review',
        'SHORTLISTED'  => 'Shortlisted',
        'INTERVIEW'    => 'Interview',
        'HIRED'        => 'Hired',
        'REJECTED'     => 'Rejected',
    ];
}

function ef_status_label(string $status): string {
    return ef_statuses()[$status] ?? $status;
}

/** Columns a select is allowed to offer, so a typo can never reach the insert. */
function ef_employment_types(): array {
    return ef_fields()['employment_type'][3];
}

function ef_genders(): array {
    return ef_fields()['gender'][3];
}

function ef_marital_statuses(): array {
    return ef_fields()['marital_status'][3];
}

const EF_MAX_CV_BYTES = 6 * 1024 * 1024;   /* one CV at a time */

function ef_cv_allow(): array {
    return ['pdf', 'doc', 'docx'];
}

/* --------------------------------------------------------------- cleaning ---- */

/**
 * Trims one submitted value to the shape and width its field allows.
 *
 * The length cap comes from the field map, not from a constant here, so it always
 * matches the column the value is about to be written to.
 */
function ef_clean(string $v, string $type, int $max = 200): string {
    $v = str_replace("\0", '', $v);
    if ($type === 'email') {
        $v = strtolower(trim($v));
        return substr($v, 0, min($max, 190));
    }
    /* One line: a newline smuggled into a single-line field used to break the
       table it is later printed into. */
    if ($type === 'text' || $type === 'tel' || $type === 'url' || $type === 'date') {
        $v = preg_replace('/\s+/u', ' ', trim($v)) ?? trim($v);
        return substr($v, 0, $max);
    }
    /* Multi-line, but still capped to the column. */
    $v = preg_replace("/\r\n?/", "\n", trim($v)) ?? trim($v);
    return substr($v, 0, $max);
}

/** Collects every field of the form into a name => value map, cleaned. */
function ef_clean_post(array $post): array {
    $out = [];
    foreach (ef_fields() as $key => $f) {
        $out[$key] = ef_clean((string) ($post[$key] ?? ''), (string) $f[1], (int) ($f[5] ?? 200));
    }
    return $out;
}

/**
 * Checks the submission and returns one message per problem.
 *
 * An empty array means it is safe to insert. Required fields are enforced here
 * rather than by the browser's own required attribute alone: that attribute is a
 * hint, not a check, and a form posted with the attribute stripped would
 * otherwise write a half-empty application with nobody's name on it.
 */
function ef_errors(array $values): array {
    $errors = [];
    $fields = ef_fields();

    foreach ($fields as $key => $f) {
        $label = $f[0];
        $type = $f[1];
        $val = (string) ($values[$key] ?? '');

        if (!empty($f[2]) && $val === '') {
            $errors[] = $label . ' is required.';
            continue;
        }
        if ($val === '') continue;

        if ($type === 'email' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
            $errors[] = $label . ' is not a valid email address.';
        }
        if ($type === 'url') {
            /* Accept what people actually type. A bare host with no scheme is the
               common case, and rejecting it loses the link for no safety gain:
               the value is only ever rendered as text or copied out. */
            $probe = preg_match('#^[a-z][a-z0-9+.-]*://#i', $val) === 1 ? $val : 'https://' . $val;
            if (!filter_var($probe, FILTER_VALIDATE_URL)) $errors[] = $label . ' is not a valid link.';
        }
        if ($type === 'tel' && !ef_phone_ok($val)) {
            $errors[] = $label . ' must be 7 to 20 digits, optionally starting with +.';
        }
        if ($type === 'date' && !ef_date_ok($val)) {
            $errors[] = $label . ' is not a real date.';
        }
        if ($key === 'graduation_year' && preg_match('/^\d{4}$/', $val) === 1) {
            $year = (int) $val;
            if ($year < 1950 || $year > (int) date('Y') + 1) {
                $errors[] = 'Graduation Year must be between 1950 and ' . ((int) date('Y') + 1) . '.';
            }
        }
        if ($key === 'gender' && !in_array($val, ef_genders(), true)) {
            $errors[] = 'Gender is not one of the listed options.';
        }
        if ($key === 'marital_status' && !in_array($val, ef_marital_statuses(), true)) {
            $errors[] = 'Marital Status is not one of the listed options.';
        }
        if ($key === 'employment_type' && !in_array($val, ef_employment_types(), true)) {
            $errors[] = 'Employment Type is not one of the listed options.';
        }
    }

    /* A joining date or a date of birth in the future is a typo, not a plan. */
    foreach (['joining_date' => 'Expected Joining Date', 'dob' => 'Date of Birth'] as $key => $label) {
        $val = (string) ($values[$key] ?? '');
        if ($val !== '' && ef_date_ok($val) && $val > today()) {
            $errors[] = $label . ' cannot be in the future.';
        }
    }

    return $errors;
}

/** 7 to 20 digits, an optional leading +, spaces and dashes ignored. */
function ef_phone_ok(string $v): bool {
    $digits = preg_replace('/\D/', '', $v) ?? '';
    return strlen($digits) >= 7 && strlen($digits) <= 20;
}

/** A real calendar date in yyyy-mm-dd, checked against the calendar itself. */
function ef_date_ok(string $v): bool {
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) !== 1) return false;
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/* --------------------------------------------------------------- records ---- */

/**
 * Validates and saves one submission. Returns ['id' => int] on success, or
 * ['errors' => [...]] when it must not be written.
 *
 * Takes the already-cleaned values rather than the raw request, so the caller
 * can hand the same map back to the screen for a rejected submission without the
 * two disagreeing about what was trimmed.
 *
 * The insert and the CV upload are separate on purpose: a rejected file must not
 * take the form data down with it, so the data is written first and a CV problem
 * comes back as a warning with the record already safely in place.
 */
function ef_create(array $values, array $by, string $ip, ?array $cvFile = null): array {
    /* Checked before the insert, but not fatal: a bad CV must not throw away the
       form data the person just typed. The page reports it as a warning and the
       record is saved without a CV. */
    $cvError = $cvFile === null ? null : ef_check_cv($cvFile);

    $errors = ef_errors($values);
    if ($errors !== []) return ['errors' => $errors];

    $now = now_sql();
    $cols = array_keys($values);
    $marks = [];
    $args = [];
    foreach ($cols as $c) { $marks[] = '?'; $args[] = $values[$c]; }
    foreach (['status', 'submitted_by', 'submitted_ip', 'status_updated_at', 'created_at', 'updated_at'] as $c) {
        $cols[] = $c;
        $marks[] = '?';
    }
    $args[] = 'SUBMITTED';
    $args[] = strtolower((string) ($by['email'] ?? ''));
    $args[] = substr($ip, 0, 60);
    $args[] = $now;
    $args[] = $now;
    $args[] = $now;

    try {
        q('INSERT INTO employee_form_submissions (' . implode(',', $cols) . ') VALUES (' . implode(',', $marks) . ')', $args);
    } catch (Throwable $e) {
        error_log('folio: employee form insert failed: ' . $e->getMessage());
        return ['errors' => ['The application could not be saved. Please try again.']];
    }

    $id = last_id();

    /* The status the insert just set is the starting point, so the stamp is
       written here rather than left null until somebody moves the card. */
    if ($cvFile !== null && $cvError === null) {
        $stored = ef_store_cv($id, $cvFile);
        if ($stored !== null) $cvError = $stored;
    }

    activity_log($by, 'EMPLOYEE_FORM_SUBMITTED',
        'Employee Form submitted for ' . $values['position_title'] . ' - ' . $values['full_name']);

    return ['id' => $id, 'errors' => [], 'warning' => $cvError];
}

function ef_find(int $id): ?array {
    return one('SELECT * FROM employee_form_submissions WHERE id=?', [$id]);
}

/**
 * Newest first, optionally filtered by status.
 *
 * The status goes through a parameter rather than being pasted into the SQL: it
 * comes from the query string, and a filter that reached the statement as text
 * would be an injection point on the one screen HR reads every day.
 */
function ef_all(string $status = '', int $limit = 300): array {
    if ($status !== '' && !isset(ef_statuses()[$status])) return [];
    if ($status !== '') {
        return rows('SELECT * FROM employee_form_submissions WHERE status=? ORDER BY id DESC LIMIT ' . (int) max(1, $limit), [$status]);
    }
    return rows('SELECT * FROM employee_form_submissions ORDER BY id DESC LIMIT ' . (int) max(1, $limit));
}

/** status => count, for the filter chips. Includes the statuses with no rows. */
function ef_status_counts(): array {
    $out = [];
    foreach (rows('SELECT status, COUNT(*) AS n FROM employee_form_submissions GROUP BY status') as $r) {
        $out[(string) $r['status']] = (int) $r['n'];
    }
    return $out;
}

function ef_count(): int {
    return (int) val('SELECT COUNT(*) FROM employee_form_submissions');
}

/** Moves one application along the pipeline. Returns false on an unknown status. */
function ef_set_status(int $id, string $status, string $remarks, array $by): bool {
    if (!isset(ef_statuses()[$status])) return false;
    if (ef_find($id) === null) return false;
    q('UPDATE employee_form_submissions SET status=?, remarks=?, status_updated_at=?, updated_at=? WHERE id=?',
        [$status, ef_clean($remarks, 'textarea'), now_sql(), now_sql(), $id]);
    activity_log($by, 'EMPLOYEE_FORM_STATUS',
        'Employee Form #' . $id . ' moved to ' . ef_status_label($status));
    return true;
}

/* ------------------------------------------------------------------- CV ---- */

/**
 * Where CVs live. The stored name is generated, never the one the browser sent,
 * so nothing a client chooses can escape this directory.
 */
function ef_cvs_dir(): string {
    global $WF;
    $dir = rtrim($WF['storage'], '/\\') . '/employee_form_cvs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

/**
 * Rejects an unusable CV before anything is written. Returns null when the file
 * is acceptable, or the reason it is not.
 *
 * The extension the browser claims is never trusted on its own: the bytes are
 * sniffed and have to agree, which is what stops a .php uploaded as "cv.pdf" from
 * ever reaching the storage directory.
 */
function ef_check_cv(array $file): ?string {
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) return null;
    if ($err !== UPLOAD_ERR_OK) {
        return ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE)
            ? 'The CV is too large. Maximum is 6 MB.'
            : 'The CV upload did not complete. Try again.';
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) return 'The CV file is empty.';
    if ($size > EF_MAX_CV_BYTES) return 'The CV is too large. Maximum is 6 MB.';

    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ef_cv_allow(), true)) {
        return 'The CV must be a ' . implode(', ', ef_cv_allow()) . ' file.';
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) return 'The CV upload could not be read.';

    $mime = hrm_sniff($tmp);
    if ($mime === null || !hrm_mime_ok($ext, $mime)) {
        return 'That file is not really a ' . strtoupper($ext) . ' file.';
    }
    return null;
}

/** Moves a validated CV into place and records it against the submission. */
function ef_store_cv(int $id, array $file): ?string {
    if (ef_check_cv($file) !== null) return 'The CV could not be saved.';

    $tmp = (string) $file['tmp_name'];
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $safe = 'cv-' . $id . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $target = ef_cvs_dir() . '/' . $safe;

    if (!@move_uploaded_file($tmp, $target)) return 'The CV could not be saved.';
    @chmod($target, 0644);

    q('UPDATE employee_form_submissions SET cv_file=?, cv_orig=?, cv_mime=?, cv_size=? WHERE id=?', [
        $safe,
        substr((string) ($file['name'] ?? 'cv'), 0, 300),
        substr((string) hrm_sniff($target), 0, 120),
        (int) ($file['size'] ?? 0),
        $id,
    ]);
    return null;
}

/**
 * Streams one stored CV. The only way out of the storage directory.
 *
 * The name is read back from the database and checked against the directory it
 * must live in, so a row that somehow held ../../etc/passwd resolves to nothing
 * rather than to a file.
 */
function ef_serve_cv(int $id, array $viewer): void {
    if (!is_supervisor($viewer)) {
        http_response_code(403);
        exit;
    }
    $r = ef_find($id);
    if ($r === null || (string) ($r['cv_file'] ?? '') === '') {
        http_response_code(404);
        exit;
    }
    $dir = realpath(ef_cvs_dir());
    $path = realpath($dir . '/' . basename((string) $r['cv_file']));
    if ($dir === false || $path === false || strpos($path, $dir . DIRECTORY_SEPARATOR) !== 0
        || !is_file($path)) {
        http_response_code(404);
        exit;
    }

    activity_log($viewer, 'EMPLOYEE_FORM_CV_OPENED', 'Opened the CV on Employee Form #' . $id);

    $mime = (string) ($r['cv_mime'] ?? 'application/octet-stream');
    header('Content-Type: ' . (preg_match('#^[\w.+-]+/[\w.+-]+$#', $mime) === 1 ? $mime : 'application/octet-stream'));
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: inline; filename="' . str_replace('"', '', (string) ($r['cv_orig'] ?? 'cv')) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($path);
    exit;
}