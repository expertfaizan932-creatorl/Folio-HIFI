<?php
/**
 * WorkFolio PHP - the HRM employee information form.
 *
 * A nine-section form that an employee fills in themselves through a personal,
 * token-protected link, which HR watches from the sidebar. The split matters:
 * sections 1-7 and 9 are the employee's own words, and section 8 is HR's record
 * alone, so the two are written through separate functions and neither can
 * overwrite the other's half.
 *
 * Two confirmations are mandatory by design rather than by preference. A bank
 * account typed once and never checked is the single most common source of a
 * payroll dispute, and an asset nobody acknowledged is how a company loses track
 * of its own laptops. Neither can be submitted without the tick, and both record
 * who confirmed and when.
 */

/** Sections in display order. Drives the public form and the review page. */
function hrm_sections(): array {
    return [
        ['key' => 'personal',  'title' => 'Personal Information',            'owner' => 'employee'],
        ['key' => 'family',    'title' => 'Father / Family Information',    'owner' => 'employee'],
        ['key' => 'education', 'title' => 'Education & Qualification',      'owner' => 'employee'],
        ['key' => 'professional', 'title' => 'Professional Information',    'owner' => 'employee'],
        ['key' => 'salary',    'title' => 'Salary & Bank Information',      'owner' => 'employee'],
        ['key' => 'assets',    'title' => 'Company Assets',                 'owner' => 'employee'],
        ['key' => 'documents', 'title' => 'Required Documents',             'owner' => 'employee'],
        ['key' => 'hr',        'title' => 'HR Record',                      'owner' => 'hr'],
        ['key' => 'confirm',   'title' => 'Employee Confirmation',          'owner' => 'employee'],
    ];
}

/**
 * Every employee-owned field: key => [label, type, required, options, hint].
 * A single map is the whole form, so the page, the save, the validation and the
 * review can never disagree about what exists or what is mandatory.
 */
function hrm_fields(): array {
    return [
        /* 1. Personal Information */
        'full_name'           => ['Employee Name', 'text', true, [], ''],
        'father_name'         => ["Father's Name", 'text', false, [], ''],
        'cnic'                => ['CNIC Number', 'text', true, [], 'Format: 00000-0000000-0'],
        'dob'                 => ['Date of Birth', 'date', true, [], ''],
        'gender'              => ['Gender', 'select', true, ['Male', 'Female', 'Other'], ''],
        'marital_status'      => ['Marital Status', 'select', false, ['Single', 'Married', 'Divorced', 'Widowed'], ''],
        'contact_number'      => ['Contact Number', 'text', true, [], ''],
        'personal_email'      => ['Personal Email', 'email', false, [], 'Not your WorkFolio login'],
        'current_address'     => ['Current Address', 'textarea', true, [], ''],
        'permanent_address'   => ['Permanent Address', 'textarea', false, [], ''],

        /* 2. Father / Family Information */
        'father_cnic'         => ["Father's CNIC Number", 'text', false, [], ''],
        'father_contact'      => ["Father's Contact Number", 'text', false, [], ''],
        'father_occupation'   => ["Father's Occupation", 'text', false, [], ''],
        'emergency_contact_name'   => ['Emergency Contact Person', 'text', true, [], ''],
        'emergency_contact_number' => ['Emergency Contact Number', 'text', true, [], ''],
        'emergency_relationship'   => ['Relationship with Employee', 'text', true, [], ''],

        /* 3. Education & Qualification */
        'highest_degree'      => ['Last / Highest Degree', 'text', true, [], ''],
        'degree_program'      => ['Degree / Program Name', 'text', false, [], ''],
        'institute'           => ['Institute / University', 'text', false, [], ''],
        'graduation_year'     => ['Graduation / Completion Year', 'text', false, [], 'e.g. 2019'],
        'certifications'      => ['Additional Certifications', 'textarea', false, [], 'One per line'],
        'skills'              => ['Skills / Expertise', 'textarea', false, [], 'One per line'],

        /* 4. Professional Information. Employee ID, department, designation and
           joining date are already true in the roster, so they arrive pre-filled
           from the employees table and are read-only here rather than retyped. */
        'employee_code'       => ['Employee ID', 'text', false, [], ''],
        'joining_date'        => ['Date of Joining', 'date', false, [], ''],
        'designation'         => ['Designation', 'text', false, [], ''],
        'department'          => ['Department', 'text', false, [], ''],
        'employment_type'     => ['Employment Type', 'select', false, ['Full Time', 'Part Time', 'Contract', 'Intern', 'Consultant'], ''],
        'reporting_manager'   => ['Reporting Manager', 'text', false, [], ''],
        'previous_company'    => ['Previous Company', 'text', false, [], ''],
        'previous_experience' => ['Previous Experience', 'textarea', false, [], ''],
        'portfolio_link'      => ['Portfolio Link', 'url', false, [], ''],
        'linkedin'            => ['LinkedIn Profile', 'url', false, [], ''],

        /* 5. Salary & Bank Information */
        'salary'              => ['Salary', 'text', true, [], 'Monthly, in PKR'],
        'bank_name'           => ['Bank Name', 'text', true, [], ''],
        'account_title'       => ['Account Title', 'text', true, [], ''],
        'account_number'      => ['Account Number / IBAN', 'text', true, [], ''],
    ];
}

/** Fields HR alone owns. Never writable through the employee's token link. */
function hrm_hr_fields(): array {
    return [
        'probation_period'  => ['Probation Period', 'text', false, [], 'e.g. 3 months'],
        'probation_end_date' => ['Probation End Date', 'date', false, [], ''],
        'leave_record'      => ['Leave Record', 'textarea', false, [], ''],
        'attendance_record' => ['Attendance Record', 'textarea', false, [], ''],
        'performance_review' => ['Performance Review', 'textarea', false, [], ''],
        'hr_remarks'        => ['HR Remarks', 'textarea', false, [], ''],
        'employment_status' => ['Employment Status', 'select', true,
            ['Active', 'On Leave', 'Resigned', 'Terminated'], ''],
    ];
}

/** The seven documents the form asks for, in order. */
function hrm_document_keys(): array {
    return [
        'cnic_copy'        => 'CNIC Copy',
        'father_cnic_copy' => "Father's CNIC Copy",
        'degree'           => 'Latest Degree / Educational Certificate',
        'cv'               => 'CV / Resume',
        'experience_letter' => 'Experience Letter (if applicable)',
        'picture'          => 'Passport-size Picture',
        'agreement'        => 'Signed Employment Agreement',
    ];
}

/** Field keys that arrive from the roster rather than the form. */
function hrm_readonly_fields(): array {
    return ['employee_code', 'joining_date', 'designation', 'department'];
}

/* ----------------------------------------------------------------- records ---- */

/**
 * The employee's record, created on first use so HR never has to make a record
 * before there is something to put in it. Returns null only if the employee row
 * itself is missing.
 */
function hrm_record(string $email): ?array {
    $email = strtolower(trim($email));
    $r = one('SELECT * FROM employee_hrm_records WHERE email=?', [$email]);
    if ($r !== null) return $r;
    $emp = employee_find_by_email($email);
    if ($emp === null) return null;
    try {
        q('INSERT INTO employee_hrm_records (email, form_token, status, employee_code,
             joining_date, designation, department, created_at, last_saved_at)
           VALUES (?,?,?,?,?,?,?,?,?)', [
            $email, hrm_new_token(), 'DRAFT',
            $emp['id'] ?? null, $emp['join_date'] ?? null,
            $emp['designation'] ?? null, $emp['department'] ?? null,
            now_sql(), now_sql(),
        ]);
    } catch (Throwable $e) {
        error_log('folio: hrm record create failed: ' . $e->getMessage());
    }
    return one('SELECT * FROM employee_hrm_records WHERE email=?', [$email]);
}

/** 32 bytes of CSPRNG, hex. The token is the only thing that opens the form. */
function hrm_new_token(): string {
    return bin2hex(random_bytes(32));
}

/** Resolves a public token to its employee, or null when it is not a real one. */
function hrm_employee_by_token(string $token): ?array {
    $token = trim($token);
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $r = one('SELECT email FROM employee_hrm_records WHERE form_token=?', [$token]);
    if ($r === null) return null;
    return employee_find_by_email((string) $r['email']);
}

/** Issues a fresh link, which instantly invalidates the previous one. */
function hrm_rotate_token(string $email): string {
    $t = hrm_new_token();
    q('UPDATE employee_hrm_records SET form_token=? WHERE email=?', [$t, strtolower($email)]);
    return $t;
}

/** Absolute public link for an employee, safe to paste into WhatsApp or mail. */
function hrm_public_url(string $token): string {
    return app_origin() . base_url('/h/' . $token);
}

/** Everything HR needs on the overview row, without loading every record. */
function hrm_status_map(): array {
    $out = [];
    /* `updated_at` is deliberately absent: employee_hrm_records has no such
       column - the equivalent is last_saved_at - and selecting a column that
       does not exist is a fatal on MySQL, not an empty field. Selecting
       last_saved_at AS updated_at keeps the array key stable for callers. */
    foreach (rows('SELECT email, status, bank_confirmed, signature_name, confirmed_at,
                          submitted_at, last_saved_at AS updated_at FROM employee_hrm_records') as $r) {
        $out[strtolower((string) $r['email'])] = $r;
    }
    return $out;
}

/* ------------------------------------------------------------------ saving ---- */

/**
 * Writes the employee-owned fields. Read-only fields and anything HR owns are
 * dropped before the query, so a hand-crafted POST cannot use this endpoint to
 * rewrite HR's section or to restate their own employee ID.
 */
function hrm_save_employee(array $rec, array $post): array {
    $map = hrm_fields();
    $skip = hrm_readonly_fields();
    $cols = []; $vals = [];
    foreach ($map as $key => $f) {
        if (in_array($key, $skip, true)) continue;
        $cols[] = $key; $vals[] = hrm_clean((string) ($post[$key] ?? ''), $f[1]);
    }
    /* Kept in step with the roster so the review page shows one truth. */
    $cols[] = 'bank_confirmed';       $vals[] = !empty($post['bank_confirmed']) ? 1 : 0;
    $cols[] = 'bank_confirm_date';   $vals[] = !empty($post['bank_confirmed']) ? today() : null;
    $cols[] = 'last_saved_at';       $vals[] = now_sql();

    $set = implode(', ', array_map(function ($c) { return $c . '=?'; }, $cols));
    $vals[] = $rec['email'];
    q('UPDATE employee_hrm_records SET ' . $set . ' WHERE email=?', $vals);
    return hrm_missing_required($post);
}

/** HR's half of the record. */
function hrm_save_hr(array $rec, array $post, string $who): void {
    $cols = []; $vals = [];
    foreach (hrm_hr_fields() as $key => $f) {
        $cols[] = $key; $vals[] = hrm_clean((string) ($post[$key] ?? ''), $f[1]);
    }
    $cols[] = 'hr_updated_by'; $vals[] = $who;
    $cols[] = 'hr_updated_at'; $vals[] = now_sql();

    $set = implode(', ', array_map(function ($c) { return $c . '=?'; }, $cols));
    $vals[] = $rec['email'];
    q('UPDATE employee_hrm_records SET ' . $set . ' WHERE email=?', $vals);
}

/** Clamps a value to something a text field can safely hold. */
function hrm_clean(string $v, string $type): ?string {
    $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v) ?? '');
    if ($v === '') return null;
    if ($type === 'date')      return substr($v, 0, 10);
    if ($type === 'text')      return substr($v, 0, 200);
    if ($type === 'url' || $type === 'email') return substr($v, 0, 400);
    return substr($v, 0, 2000);
}

/** Required employee fields that were left empty, by field key. */
function hrm_missing_required(array $post): array {
    $missing = [];
    foreach (hrm_fields() as $key => $f) {
        if (empty($f[2])) continue;
        if (in_array($key, hrm_readonly_fields(), true)) continue;
        if (trim((string) ($post[$key] ?? '')) === '') $missing[] = $key;
    }
    return $missing;
}

/**
 * What must be true before a submission counts. The two acknowledgements are
 * non-negotiable, and both are refused with a message that says which one.
 */
function hrm_blockers(array $rec, array $assets): array {
    $b = [];
    if (trim((string) ($rec['full_name'] ?? '')) === '')  $b[] = 'Personal Information: employee name is required.';
    if (trim((string) ($rec['cnic'] ?? '')) === '')       $b[] = 'Personal Information: CNIC number is required.';
    if (trim((string) ($rec['account_number'] ?? '')) === '') $b[] = 'Salary & Bank: account number is required.';

    /* The tick the user asked for, and the reason it exists. */
    if ((int) ($rec['bank_confirmed'] ?? 0) !== 1) {
        $b[] = 'Salary & Bank: you must confirm the bank account details are correct and belong to you.';
    }
    foreach ($assets as $a) {
        if ((int) ($a['acknowledged'] ?? 0) !== 1) {
            $b[] = 'Company Assets: "' . (string) ($a['asset_name'] ?: 'unnamed asset')
                 . '" must be acknowledged before you can submit.';
        }
    }
    if (trim((string) ($rec['signature_name'] ?? '')) === '' && trim((string) ($rec['signature_png'] ?? '')) === '') {
        $b[] = 'Employee Confirmation: a signature is required.';
    }
    return $b;
}

/* ------------------------------------------------------------------ assets ---- */

function hrm_assets(string $email): array {
    return rows('SELECT * FROM hrm_assets WHERE email=? ORDER BY id', [strtolower($email)]);
}

/**
 * Replaces the asset rows from one form submission. Assets are keyed by a hidden
 * id: a row that already exists is updated, one without an id is inserted, and a
 * row whose id belongs to another employee is ignored outright rather than
 * trusted, because the id arrives from the browser.
 */
function hrm_save_assets(string $email, array $post): void {
    $email = strtolower($email);
    $mine = [];
    foreach (hrm_assets($email) as $a) $mine[(int) $a['id']] = true;

    $ids = $post['asset_id'] ?? [];
    $names = $post['asset_name'] ?? [];
    if (!is_array($names)) return;

    foreach ($names as $i => $nm) {
        $id = (int) (is_array($ids) ? ($ids[$i] ?? 0) : 0);
        $get = function (string $k) use ($post, $i) {
            $v = $post[$k] ?? [];
            return is_array($v) ? (string) ($v[$i] ?? '') : '';
        };
        $row = [
            hrm_clean((string) $nm, 'text'),
            hrm_clean($get('asset_type'), 'text'),
            hrm_clean($get('asset_serial'), 'text'),
            hrm_clean($get('issued_date'), 'date'),
            hrm_clean($get('condition_issued'), 'text'),
            !empty($get('acknowledged')) ? 1 : 0,
            !empty($get('acknowledged')) ? today() : null,
            hrm_clean($get('return_date'), 'date'),
            hrm_clean($get('condition_return'), 'text'),
            hrm_clean($get('asset_remarks'), 'textarea'),
        ];
        if ($row[0] === null && $row[1] === null && $row[2] === null) {
            /* a completely blank line is an artefact of the repeater, not data */
            if ($id > 0 && isset($mine[$id])) q('DELETE FROM hrm_assets WHERE id=? AND email=?', [$id, $email]);
            continue;
        }
        if ($id > 0 && isset($mine[$id])) {
            q('UPDATE hrm_assets SET asset_name=?, asset_type=?, asset_serial=?, issued_date=?,
                 condition_issued=?, acknowledged=?, ack_date=?, return_date=?, condition_return=?,
                 remarks=? WHERE id=? AND email=?', array_merge($row, [$id, $email]));
        } else {
            q('INSERT INTO hrm_assets (email, asset_name, asset_type, asset_serial, issued_date,
                 condition_issued, acknowledged, ack_date, return_date, condition_return, remarks)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)', array_merge([$email], $row));
        }
    }
}

/* --------------------------------------------------------------- documents ---- */

/**
 * Upload handling, written from scratch because nothing in this app had needed a
 * file input before. Three rules earn their keep: the extension has to be on the
 * allow-list, the bytes have to actually be the type they claim, and the stored
 * name is generated rather than taken from the client. A filename that survives
 * from the browser is how a directory-traversal or a double-extension gets in.
 */
function hrm_docs_dir(): string {
    global $WF;
    $dir = rtrim($WF['storage'], '/\\') . '/hrm_docs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function hrm_doc_allow(): array {
    return [
        'cnic_copy'        => ['pdf', 'jpg', 'jpeg', 'png'],
        'father_cnic_copy' => ['pdf', 'jpg', 'jpeg', 'png'],
        'degree'           => ['pdf', 'jpg', 'jpeg', 'png'],
        'cv'               => ['pdf', 'doc', 'docx'],
        'experience_letter' => ['pdf', 'doc', 'docx'],
        'picture'          => ['jpg', 'jpeg', 'png'],
        'agreement'        => ['pdf', 'jpg', 'jpeg', 'png'],
    ];
}

/** Real MIME types accepted, per extension group, detected from the content. */
function hrm_mime_ok(string $ext, string $mime): bool {
    $mime = strtolower(trim($mime));
    if ($ext === 'pdf')  return $mime === 'application/pdf';
    if ($ext === 'png')  return $mime === 'image/png';
    if ($ext === 'jpg' || $ext === 'jpeg') return in_array($mime, ['image/jpeg', 'image/jpg'], true);
    if ($ext === 'doc')  return $mime === 'application/msword';
    if ($ext === 'docx') return $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    return false;
}

/** Sniffs the true content type from the bytes themselves. */
function hrm_sniff(string $path): ?string {
    if (!function_exists('finfo_open')) return null;
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi === false) return null;
    $m = finfo_file($fi, $path);
    finfo_close($fi);
    return is_string($m) ? $m : null;
}

const HRM_MAX_UPLOAD = 6 * 1024 * 1024;   /* one document at a time */

/**
 * Validates and stores one uploaded document. Returns [fileName|null, error|null].
 * The name written to disk is generated, so nothing the client sent survives as a
 * path; only the original name is kept, as a label for HR to read.
 */
function hrm_store_upload(string $email, string $docKey, array $file): array {
    $email = strtolower($email);
    if (!isset(hrm_doc_allow()[$docKey])) return [null, 'Unknown document type.'];

    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) return [null, null];      /* nothing sent, fine */
    if ($err !== UPLOAD_ERR_OK) {
        return [null, $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE
            ? 'That file is too large. Maximum is 6 MB.'
            : 'The upload did not complete. Try again.'];
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) return [null, 'The upload could not be read.'];

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > HRM_MAX_UPLOAD) return [null, 'That file is too large. Maximum is 6 MB.'];

    $orig = (string) ($file['name'] ?? 'document');
    $orig = preg_replace('/[\x00-\x1F\x7F\/\\\\]/', '', $orig) ?? 'document';
    $ext  = strtolower((string) pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, hrm_doc_allow()[$docKey], true)) {
        return [null, 'Not an accepted file type for this document. Allowed: '
            . implode(', ', hrm_doc_allow()[$docKey]) . '.'];
    }

    $mime = hrm_sniff($tmp);
    if ($mime === null || !hrm_mime_ok($ext, $mime)) {
        return [null, 'That file is not really a ' . strtoupper($ext)
            . '. It was rejected because its contents do not match its name.'];
    }

    $safe   = preg_replace('/[^a-z0-9]/', '', strtolower(explode('@', $email)[0])) ?? 'emp';
    $name   = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(8)), 0, 12) . '.' . $ext;
    $target = hrm_docs_dir() . '/' . $safe . '-' . $name;
    if (@move_uploaded_file($tmp, $target) === false) return [null, 'The file could not be saved.'];
    @chmod($target, 0640);

    q('INSERT INTO hrm_documents (email, doc_key, file_name, orig_name, mime, size_bytes, uploaded_at)
       VALUES (?,?,?,?,?,?,?)', [
        $email, $docKey, $name, substr($orig, 0, 300), $mime, $size, now_sql(),
    ]);
    return [$name, null];
}

function hrm_documents(string $email): array {
    return rows('SELECT * FROM hrm_documents WHERE email=? ORDER BY id', [strtolower($email)]);
}

/** Every document slot that still has no file behind it. */
function hrm_documents_missing(string $email): array {
    $have = [];
    foreach (hrm_documents($email) as $d) $have[(string) $d['doc_key']] = true;
    $out = [];
    foreach (hrm_document_keys() as $k => $label) if (!isset($have[$k])) $out[$k] = $label;
    return $out;
}

/**
 * Streams one stored document. This is the only way out of data/hrm_docs, and it
 * re-checks the caller every time rather than trusting the URL - the HR role can
 * only read employees it is allowed to see, and the employee's own token can
 * read only its own.
 */
function hrm_serve_document(string $email, string $docKey, string $fileName, ?array $viewer, string $tokenEmail = ''): void {
    $docKey = preg_replace('/[^a-z0-9_]/', '', strtolower($docKey)) ?? '';
    $fileName = basename((string) $fileName);
    if ($docKey === '' || $fileName === '' || strpos($fileName, '.') === false) {
        http_response_code(404); exit;
    }
    if ($viewer !== null) {
        /* Covers the viewer being the employee too, so an employee opening their
           own document from the review page is not turned away by this check. */
        if (!access_can_view_email($viewer, $email)) { http_response_code(403); exit; }
    } elseif (strtolower($tokenEmail) !== strtolower($email)) {
        http_response_code(403); exit;
    }

    /* One expected path, built from the generated name. Anything the client sent
       that does not reproduce this exact filename is not served at all. */
    $safe = preg_replace('/[^a-z0-9]/', '', strtolower(explode('@', $email)[0])) ?? 'emp';
    $dir = hrm_docs_dir();
    $real = @realpath($dir . '/' . $safe . '-' . $fileName);
    $rdir = @realpath($dir);
    if ($real === false || $rdir === false
        || strpos($real, $rdir . DIRECTORY_SEPARATOR) !== 0
        || basename($real) !== $safe . '-' . $fileName
        || !is_file($real)) {
        http_response_code(404);
        exit;
    }

    /* The stored MIME type is read back from the database rather than taken from
       the request, so a crafted Content-Type cannot change how the file is shown. */
    $row = one('SELECT mime FROM hrm_documents WHERE email=? AND doc_key=? AND file_name=?',
               [strtolower($email), $docKey, $fileName]);
    if ($row === null) { http_response_code(404); exit; }

    $mime = (string) ($row['mime'] ?? '');
    header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
    header('Content-Length: ' . (string) filesize($real));
    header('Content-Disposition: inline; filename="' . $fileName . '"');
    /* These are identity documents. They must never end up in a shared cache,
       a proxy log, or a browser disk cache. */
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; object-src \'none\'; sandbox');
    readfile($real);
    exit;
}

/* --------------------------------------------------------------- signature ---- */

const HRM_MAX_SIGNATURE_B64 = 160 * 1024;   /* ~120 KB of PNG, generous for a canvas */

/**
 * Accepts a signature drawn on the canvas or a typed full name, never both
 * half-trusted. The canvas sends a PNG data URL; it is re-checked here because
 * whatever arrives in a POST is the client's claim, not a drawing.
 *
 * Returns ['kind' => 'png'|'typed', 'value' => string] on success,
 * ['kind' => null, 'error' => string] on a bad value, and
 * ['kind' => null, 'error' => null] when nothing was supplied at all.
 */
function hrm_signature_from(array $post): array {
    $png  = trim((string) ($post['signature_png'] ?? ''));
    $typed = trim((string) ($post['signature_name'] ?? ''));

    if ($png !== '') {
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $png, $m)) {
            return ['kind' => null, 'error' => 'The drawn signature could not be read. Please sign again.'];
        }
        if (strlen($m[1]) > HRM_MAX_SIGNATURE_B64) {
            return ['kind' => null, 'error' => 'That signature image is too large. Please sign again.'];
        }
        $raw = base64_decode($m[1], true);
        if ($raw === false || strlen($raw) < 8
            || substr($raw, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return ['kind' => null, 'error' => 'The drawn signature could not be read. Please sign again.'];
        }
        return ['kind' => 'png', 'value' => $m[1]];
    }

    /* Typed fallback, so a keyboard-only or touch-less device is not stuck. */
    if ($typed !== '') {
        $typed = trim(preg_replace('/\s+/', ' ', $typed) ?? '');
        if ($typed === '') return ['kind' => null, 'error' => 'Please type your full name to sign.'];
        if (mb_strlen($typed) > 120) $typed = mb_substr($typed, 0, 120);
        return ['kind' => 'typed', 'value' => $typed];
    }

    return ['kind' => null, 'error' => null];
}

/**
 * Writes a validated signature onto the record. Returns an error string, or null.
 * An existing signature survives an edit of other fields, so the employee does not
 * have to re-sign every time they correct a typo.
 */
function hrm_save_signature(array $rec, array $post): ?string {
    $supplied = trim((string) ($post['signature_png'] ?? '')) !== ''
             || trim((string) ($post['signature_name'] ?? '')) !== '';
    $already  = trim((string) ($rec['signature_png'] ?? '')) !== ''
             || trim((string) ($rec['signature_name'] ?? '')) !== '';

    if (!$supplied && $already) return null;          /* keep the old signature */
    if (!$supplied) return null;                      /* nothing yet; submit blocks it */

    $sig = hrm_signature_from($post);
    if ($sig['kind'] === null) return $sig['error'];

    q('UPDATE employee_hrm_records SET signature_type=?, signature_name=?, signature_png=? WHERE email=?', [
        $sig['kind'],
        $sig['kind'] === 'typed' ? (string) $sig['value'] : null,
        $sig['kind'] === 'png'   ? (string) $sig['value'] : null,
        $rec['email'],
    ]);
    return null;
}

/* ------------------------------------------------------------------ review ---- */

/** Masks an account number for display: keeps the ends, hides the middle. */
function hrm_mask_account(string $v): string {
    $v = preg_replace('/\s+/', '', $v) ?? $v;
    $n = strlen($v);
    if ($n === 0) return '';
    if ($n <= 4) return str_repeat('*', $n);
    return substr($v, 0, 2) . str_repeat('*', max(3, $n - 4)) . substr($v, -2);
}