<?php
/**
 * WorkFolio PHP - HR module data layer: Employee Documents (Phase 1, section 8).
 *
 * Additive like the other HR layers: it owns hr_document_categories,
 * hr_employee_documents and hr_document_history and only reads `employees` by
 * email. The HRM form already stores the seven onboarding documents against
 * employee_hrm_records; this layer is the general HR document vault that sits
 * beside it, with categories, expiry tracking, history and role-based access.
 *
 * Two rules drive the design:
 *   - A file is served only through hr_document_serve(), which re-checks the
 *     caller on every request. Nothing under <storage>/hr_docs is ever handed
 *     out because a URL guessed the name.
 *   - A sensitive category (a warning letter, a performance review) is HR's
 *     alone; the employee it is about cannot read it about themselves.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/hr.php';              /* hr_int_or_null() */

/* ------------------------------------------------------------------- seed ---- */

/**
 * The ten categories the document names, seeded once. `sensitive` is set on the
 * two an employee must not read about themselves; `expiry_tracked` on the ones
 * whose expiry HR has to watch (contracts, agreements, certificates).
 */
function hr_doc_seed(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if ((int) val('SELECT COUNT(*) FROM hr_document_categories') !== 0) return;
        $cats = [
            ['CNIC / Identity',      'cnic',              0, 0, 10],
            ['CV / Resume',          'cv',                0, 0, 20],
            ['Offer Letter',         'offer_letter',      0, 0, 30],
            ['Employment Agreement', 'agreement',         0, 1, 40],
            ['Experience Letter',    'experience_letter', 0, 0, 50],
            ['Relieving Letter',     'relieving_letter',  0, 0, 60],
            ['Certificate',          'certificate',       0, 1, 70],
            ['Warning Letter',       'warning_letter',    1, 0, 80],
            ['Performance Document', 'performance',       1, 0, 90],
            ['Other HR Document',    'other',             0, 0, 100],
        ];
        foreach ($cats as $c) {
            q('INSERT INTO hr_document_categories (name,code,`sensitive`,expiry_tracked,sort_order,active,created_at)
               VALUES (?,?,?,?,?,1,?)',
              [$c[0], $c[1], $c[2], $c[3], $c[4], now_sql()]);
        }
    } catch (Throwable $e) {
        error_log('folio: hr_doc_seed failed: ' . $e->getMessage());
    }
}

/* --------------------------------------------------------------- categories ---- */

function hr_doc_categories(bool $activeOnly = false): array {
    hr_doc_seed();
    $sql = 'SELECT * FROM hr_document_categories' . ($activeOnly ? ' WHERE active=1' : '')
         . ' ORDER BY sort_order, name';
    return rows($sql);
}

function hr_doc_category(?int $id): ?array {
    return $id ? one('SELECT * FROM hr_document_categories WHERE id=?', [$id]) : null;
}

/** id => row map, for rendering without an N+1 lookup. */
function hr_doc_category_map(): array {
    $out = [];
    foreach (hr_doc_categories() as $c) $out[(int) $c['id']] = $c;
    return $out;
}

function hr_doc_code(string $code): string {
    return preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($code))) ?? '';
}

function hr_doc_category_save(?int $id, string $name, string $code, bool $sensitive, bool $expiryTracked, int $sortOrder, bool $active): int {
    $name = trim($name);
    $code = hr_doc_code($code);
    if ($name === '' || $code === '') return 0;
    if ($id) {
        q('UPDATE hr_document_categories SET name=?, code=?, `sensitive`=?, expiry_tracked=?, sort_order=?, active=? WHERE id=?',
          [$name, $code, $sensitive ? 1 : 0, $expiryTracked ? 1 : 0, $sortOrder, $active ? 1 : 0, $id]);
        return $id;
    }
    q('INSERT INTO hr_document_categories (name,code,`sensitive`,expiry_tracked,sort_order,active,created_at)
       VALUES (?,?,?,?,?,?,?)',
      [$name, $code, $sensitive ? 1 : 0, $expiryTracked ? 1 : 0, $sortOrder, $active ? 1 : 0, now_sql()]);
    return last_id();
}

/**
 * Deleting a category does NOT delete the documents filed under it; they are
 * moved to "Other HR Document" so nothing an employee handed in disappears
 * because HR tidied a lookup.
 */
function hr_doc_category_delete(int $id): void {
    $c = hr_doc_category($id);
    if ($c === null) return;
    $other = one("SELECT id FROM hr_document_categories WHERE code='other'");
    $fallback = $other !== null && (int) $other['id'] !== $id ? (int) $other['id'] : null;
    q('UPDATE hr_employee_documents SET category_id=? WHERE category_id=?', [$fallback, $id]);
    q('DELETE FROM hr_document_categories WHERE id=?', [$id]);
}

/* ---------------------------------------------------------------- documents ---- */

function hr_documents(array $filters = []): array {
    hr_doc_seed();
    $sql = 'SELECT d.*, c.name AS category_name, c.code AS category_code,
                   c.`sensitive` AS category_sensitive, e.first_name, e.last_name
            FROM hr_employee_documents d
            LEFT JOIN hr_document_categories c ON c.id = d.category_id
            LEFT JOIN employees e ON LOWER(e.email) = d.email';
    $where = []; $p = [];
    if (!empty($filters['email'])) { $where[] = 'd.email = ?'; $p[] = strtolower((string) $filters['email']); }
    if (!empty($filters['category_id'])) { $where[] = 'd.category_id = ?'; $p[] = (int) $filters['category_id']; }
    if (!empty($filters['q'])) { $where[] = '(d.title LIKE ? OR d.orig_name LIKE ?)'; $like = '%' . $filters['q'] . '%'; $p[] = $like; $p[] = $like; }
    if (($filters['expiry'] ?? '') === 'expired') { $where[] = "d.expiry_date IS NOT NULL AND d.expiry_date <> '' AND d.expiry_date < ?"; $p[] = today(); }
    if (($filters['expiry'] ?? '') === 'expiring') {
        $where[] = "d.expiry_date IS NOT NULL AND d.expiry_date <> '' AND d.expiry_date >= ? AND d.expiry_date <= ?";
        $p[] = today(); $p[] = date('Y-m-d', strtotime('+' . HR_DOC_EXPIRY_WARN_DAYS . ' days'));
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY d.id DESC';
    if (!empty($filters['limit'])) $sql .= ' LIMIT ' . (int) $filters['limit'];
    return rows($sql, $p);
}

function hr_document(?int $id): ?array {
    if (!$id) return null;
    return one('SELECT d.*, c.name AS category_name, c.code AS category_code,
                       c.`sensitive` AS category_sensitive, e.first_name, e.last_name
                FROM hr_employee_documents d
                LEFT JOIN hr_document_categories c ON c.id = d.category_id
                LEFT JOIN employees e ON LOWER(e.email) = d.email
                WHERE d.id=?', [$id]);
}

function hr_documents_for(string $email): array {
    return hr_documents(['email' => strtolower(trim($email))]);
}

/** Documents the given viewer may see, in newest-first order. */
function hr_documents_visible_to(array $viewer, array $filters = []): array {
    $out = [];
    foreach (hr_documents($filters) as $d) {
        if (hr_document_can_view($viewer, $d)) $out[] = $d;
    }
    return $out;
}

/* ----------------------------------------------------------------- access ---- */

function hr_doc_is_manager(?array $u): bool {
    $r = (string) ($u['role'] ?? '');
    return $r === 'SUPER_ADMIN' || $r === 'ADMIN' || $r === 'HR';
}

function hr_document_can_view(?array $viewer, array $doc): bool {
    if ($viewer === null) return false;
    if (hr_doc_is_manager($viewer)) return access_can_view_email($viewer, (string) $doc['email']);
    /* An employee may read their own document unless its category is sensitive. */
    if (strcasecmp((string) $viewer['email'], (string) $doc['email']) === 0) {
        return (int) ($doc['category_sensitive'] ?? 0) !== 1;
    }
    return false;
}

/** Only HR roles may add, edit, replace or delete documents. */
function hr_document_can_manage(?array $viewer, ?array $doc = null): bool {
    if (!hr_doc_is_manager($viewer)) return false;
    if ($doc !== null) return access_can_view_email($viewer, (string) $doc['email']);
    return true;
}

/* ------------------------------------------------------------------- files ---- */

function hr_docs_dir(): string {
    global $WF;
    $dir = rtrim($WF['storage'], '/\\') . '/hr_docs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

/** Extensions accepted for any HR document. */
function hr_doc_allow(): array {
    return ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip'];
}

/** Does the sniffed content type go with the claimed extension? */
function hr_doc_mime_ok(string $ext, string $mime): bool {
    $mime = strtolower(trim($mime));
    switch ($ext) {
        case 'pdf':  return $mime === 'application/pdf';
        case 'png':  return $mime === 'image/png';
        case 'gif':  return $mime === 'image/gif';
        case 'jpg': case 'jpeg': return in_array($mime, ['image/jpeg', 'image/jpg'], true);
        case 'txt':  return strpos($mime, 'text/') === 0;
        case 'doc':  return $mime === 'application/msword'
                        || $mime === 'application/x-ole-storage'
                        || strpos($mime, 'application/CDFV2') === 0;
        case 'docx': return $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                        || $mime === 'application/zip';
        case 'xls':  return $mime === 'application/vnd.ms-excel'
                        || $mime === 'application/x-ole-storage'
                        || strpos($mime, 'application/CDFV2') === 0;
        case 'xlsx': return $mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                        || $mime === 'application/zip';
        case 'zip':  return $mime === 'application/zip';
    }
    return false;
}

function hr_doc_sniff(string $path): ?string {
    if (!function_exists('finfo_open')) return null;
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi === false) return null;
    $m = finfo_file($fi, $path);
    finfo_close($fi);
    return is_string($m) ? $m : null;
}

const HR_DOC_MAX_UPLOAD = 10 * 1024 * 1024;   /* 10 MB */
const HR_DOC_EXPIRY_WARN_DAYS = 30;

/**
 * Validates and moves one upload into <storage>/hr_docs. Returns
 * ['name'=>string,'orig'=>string,'mime'=>string,'size'=>int,'error'=>?string];
 * a genuinely empty file input is signalled with all-empty and no error, so the
 * caller can tell "no file picked" apart from "that file was rejected".
 */
function hr_doc_store_upload(string $email, array $file): array {
    $blank = ['name' => '', 'orig' => '', 'mime' => '', 'size' => 0, 'error' => null];
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) return $blank;
    if ($err !== UPLOAD_ERR_OK) {
        $blank['error'] = ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE)
            ? 'That file is too large. Maximum is 10 MB.'
            : 'The upload did not complete. Try again.';
        return $blank;
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) { $blank['error'] = 'The upload could not be read.'; return $blank; }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > HR_DOC_MAX_UPLOAD) { $blank['error'] = 'That file is too large. Maximum is 10 MB.'; return $blank; }

    $orig = (string) ($file['name'] ?? 'document');
    $orig = preg_replace('/[\x00-\x1F\x7F\/\\\\]/', '', $orig) ?? 'document';
    $ext  = strtolower((string) pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, hr_doc_allow(), true)) {
        $blank['error'] = 'Not an accepted file type. Allowed: ' . implode(', ', hr_doc_allow()) . '.';
        return $blank;
    }
    $mime = hr_doc_sniff($tmp);
    if ($mime === null || !hr_doc_mime_ok($ext, $mime)) {
        $blank['error'] = 'That file is not really a ' . strtoupper($ext)
            . '. It was rejected because its contents do not match its name.';
        return $blank;
    }

    $safe   = preg_replace('/[^a-z0-9]/', '', strtolower((string) explode('@', $email)[0])) ?: 'emp';
    $name   = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(8)), 0, 12) . '.' . $ext;
    $target = hr_docs_dir() . '/' . $safe . '-' . $name;
    if (@move_uploaded_file($tmp, $target) === false) { $blank['error'] = 'The file could not be saved.'; return $blank; }
    @chmod($target, 0640);

    return ['name' => $name, 'orig' => substr($orig, 0, 300), 'mime' => $mime, 'size' => $size, 'error' => null];
}

/* ------------------------------------------------------------ create / edit ---- */

/** Employee name for a document title fallback, or the email when unknown. */
function hr_doc_emp_label(string $email): string {
    $e = employee_find_by_email($email);
    return $e !== null ? employee_full_name($e) : $email;
}

function hr_doc_clean_text(string $v, int $max): string {
    $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v) ?? '');
    return substr($v, 0, $max);
}

/**
 * Files one new document. Returns ['id'=>int,'error'=>?string]. The employee
 * must exist in the roster; the category must exist and be active.
 */
function hr_document_upload(string $email, array $d, array $file, string $by): array {
    $email = strtolower(trim($email));
    if (employee_find_by_email($email) === null) return ['id' => 0, 'error' => 'No such employee.'];

    $cat = hr_doc_category(hr_int_or_null($d['category_id'] ?? null));
    if ($cat === null) return ['id' => 0, 'error' => 'Pick a document category.'];

    $title = hr_doc_clean_text((string) ($d['title'] ?? ''), 200);
    if ($title === '') $title = (string) $cat['name'];

    $up = hr_doc_store_upload($email, $file);
    if ($up['error'] !== null) return ['id' => 0, 'error' => $up['error']];
    if ($up['name'] === '') return ['id' => 0, 'error' => 'Choose a file to upload.'];

    $expiry = hr_doc_clean_text((string) ($d['expiry_date'] ?? ''), 20);
    if ($expiry !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) $expiry = '';

    q('INSERT INTO hr_employee_documents (email,category_id,title,file_name,orig_name,mime,size_bytes,
        issued_date,expiry_date,notes,uploaded_by,uploaded_at,updated_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        $email, (int) $cat['id'], $title, $up['name'], $up['orig'], $up['mime'], $up['size'],
        hr_doc_clean_text((string) ($d['issued_date'] ?? ''), 20), $expiry,
        hr_doc_clean_text((string) ($d['notes'] ?? ''), 2000), strtolower(trim($by)), now_sql(), now_sql(),
    ]);
    $id = last_id();
    hr_document_history_add($id, 'UPLOADED', $by, $up['orig'] !== '' ? $up['orig'] : $title);
    return ['id' => $id, 'error' => null];
}

/** Edits a document's metadata (category, title, dates, notes). Never its file. */
function hr_document_update(int $id, array $d, string $by): string {
    $doc = hr_document($id);
    if ($doc === null) return 'Document not found.';
    $cat = hr_doc_category(hr_int_or_null($d['category_id'] ?? null));
    if ($cat === null) $cat = ['id' => $doc['category_id']];
    $title = hr_doc_clean_text((string) ($d['title'] ?? ''), 200);
    if ($title === '') $title = (string) ($doc['title'] ?? 'Document');
    $expiry = hr_doc_clean_text((string) ($d['expiry_date'] ?? ''), 20);
    if ($expiry !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) $expiry = '';
    q('UPDATE hr_employee_documents SET category_id=?, title=?, issued_date=?, expiry_date=?, notes=?, updated_at=?, expiry_notified_at=NULL
       WHERE id=?', [
        hr_int_or_null($cat['id'] ?? null), $title,
        hr_doc_clean_text((string) ($d['issued_date'] ?? ''), 20), $expiry,
        hr_doc_clean_text((string) ($d['notes'] ?? ''), 2000), now_sql(), $id,
    ]);
    hr_document_history_add($id, 'UPDATED', $by, 'Details edited');
    return '';
}

/** Swaps the file behind a document. Returns an error string, or ''. */
function hr_document_replace(int $id, array $file, string $by): string {
    $doc = hr_document($id);
    if ($doc === null) return 'Document not found.';
    $up = hr_doc_store_upload((string) $doc['email'], $file);
    if ($up['error'] !== null) return $up['error'];
    if ($up['name'] === '') return 'Choose a replacement file.';
    q('UPDATE hr_employee_documents SET file_name=?, orig_name=?, mime=?, size_bytes=?, uploaded_by=?, uploaded_at=?, updated_at=?
       WHERE id=?', [
        $up['name'], $up['orig'], $up['mime'], $up['size'], strtolower(trim($by)), now_sql(), now_sql(), $id,
    ]);
    hr_doc_remove_file((string) $doc['email'], (string) $doc['file_name']);
    hr_document_history_add($id, 'REPLACED', $by, $up['orig'] !== '' ? $up['orig'] : 'replacement');
    return '';
}

/** Deletes a document and its file. History is left as the trail. */
function hr_document_delete(int $id, string $by): void {
    $doc = hr_document($id);
    if ($doc === null) return;
    hr_document_history_add($id, 'DELETED', $by, (string) ($doc['orig_name'] ?? ''));
    hr_doc_remove_file((string) $doc['email'], (string) $doc['file_name']);
    q('DELETE FROM hr_document_history WHERE document_id=?', [$id]);
    q('DELETE FROM hr_employee_documents WHERE id=?', [$id]);
}

/** Removes one stored file by its generated name, path-checked. */
function hr_doc_remove_file(string $email, string $fileName): void {
    $fileName = basename($fileName);
    if ($fileName === '' || strpos($fileName, '.') === false) return;
    $safe = preg_replace('/[^a-z0-9]/', '', strtolower((string) explode('@', $email)[0])) ?: 'emp';
    $dir = hr_docs_dir();
    $real = @realpath($dir . '/' . $safe . '-' . $fileName);
    $rdir = @realpath($dir);
    if ($real !== false && $rdir !== false && strpos($real, $rdir . DIRECTORY_SEPARATOR) === 0 && is_file($real)) {
        @unlink($real);
    }
}

/* ----------------------------------------------------------------- history ---- */

function hr_document_history_add(int $id, string $action, string $by, string $note = ''): void {
    try {
        q('INSERT INTO hr_document_history (document_id,action,actor_email,note,created_at) VALUES (?,?,?,?,?)',
          [$id, strtoupper($action), strtolower(trim($by)), $note, now_sql()]);
    } catch (Throwable $e) {
        error_log('folio: hr_document_history_add failed: ' . $e->getMessage());
    }
}

function hr_document_history(int $id): array {
    return rows('SELECT * FROM hr_document_history WHERE document_id=? ORDER BY id DESC', [$id]);
}

/* ------------------------------------------------------------------ expiry ---- */

/** 'none' | 'ok' | 'expiring' | 'expired' for a document row. */
function hr_document_expiry_state(array $doc): string {
    $d = trim((string) ($doc['expiry_date'] ?? ''));
    if ($d === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return 'none';
    $t = strtotime($d);
    if ($t === false) return 'none';
    $today = strtotime(today());
    if ($t < $today) return 'expired';
    if ($t <= strtotime('+' . HR_DOC_EXPIRY_WARN_DAYS . ' days', $today)) return 'expiring';
    return 'ok';
}

function hr_document_expiry_badge(array $doc): string {
    $state = hr_document_expiry_state($doc);
    if ($state === 'none') return '<span class="text-muted small">&mdash;</span>';
    $map = ['ok' => 'bg-success', 'expiring' => 'bg-warning text-dark', 'expired' => 'bg-danger'];
    $label = ['ok' => 'Valid', 'expiring' => 'Expiring', 'expired' => 'Expired'][$state];
    return '<span class="badge ' . $map[$state] . '">' . $label . '</span> <span class="small text-muted">'
        . esc((string) $doc['expiry_date']) . '</span>';
}

/** Documents expiring within $days (or already expired), for dashboards. */
function hr_documents_expiring(int $days = HR_DOC_EXPIRY_WARN_DAYS, bool $includeExpired = true): array {
    hr_doc_seed();
    $sql = "SELECT d.*, c.name AS category_name, c.`sensitive` AS category_sensitive,
                   e.first_name, e.last_name
            FROM hr_employee_documents d
            LEFT JOIN hr_document_categories c ON c.id = d.category_id
            LEFT JOIN employees e ON LOWER(e.email) = d.email
            WHERE d.expiry_date IS NOT NULL AND d.expiry_date <> '' AND d.expiry_date <= ?";
    $p = [date('Y-m-d', strtotime('+' . $days . ' days'))];
    if (!$includeExpired) { $sql .= ' AND d.expiry_date >= ?'; $p[] = today(); }
    $sql .= ' ORDER BY d.expiry_date ASC';
    return rows($sql, $p);
}

/** How many documents need attention, for the dashboard card. */
function hr_documents_expiry_count(int $days = 60): int {
    try {
        return (int) val("SELECT COUNT(*) FROM hr_employee_documents
                          WHERE expiry_date IS NOT NULL AND expiry_date <> '' AND expiry_date <= ?",
                         [date('Y-m-d', strtotime('+' . $days . ' days'))]);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Announces documents that are about to expire. Called lazily like the rest of
 * the app's sweeps. A document is announced once, then at most every 7 days, so
 * a doc sitting at 25 days out does not fire an alert on every page load.
 */
function hr_documents_expiry_sweep(int $days = HR_DOC_EXPIRY_WARN_DAYS): void {
    try {
        $docs = hr_documents_expiring($days);
        if ($docs === []) return;
        $throttle = date('Y-m-d H:i:s', strtotime('-7 days'));
        foreach ($docs as $d) {
            $last = (string) ($d['expiry_notified_at'] ?? '');
            if ($last !== '' && $last > $throttle) continue;
            $state = hr_document_expiry_state($d);
            $when  = $state === 'expired' ? 'has expired' : 'expires soon';
            $title = (string) ($d['title'] ?? 'Document');
            $who   = hr_doc_emp_label((string) $d['email']);
            $link  = '/hr/documents/view/' . (int) $d['id'];
            hr_notify_roles(['SUPER_ADMIN', 'ADMIN', 'HR'], 'DOC_EXPIRY',
                'Document ' . ($state === 'expired' ? 'expired' : 'expiring'),
                $who . '\'s "' . $title . '" ' . $when . ' on ' . (string) $d['expiry_date'] . '.',
                $link);
            hr_notify((string) $d['email'], 'DOC_EXPIRY', 'Document expiry',
                'Your document "' . $title . '" ' . $when . ' on ' . (string) $d['expiry_date'] . '.', $link);
            q('UPDATE hr_employee_documents SET expiry_notified_at=? WHERE id=?', [now_sql(), (int) $d['id']]);
        }
    } catch (Throwable $e) {
        error_log('folio: hr_documents_expiry_sweep failed: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------- serve ---- */

/**
 * Streams one stored document. This is the only exit from <storage>/hr_docs and
 * re-checks the caller every time rather than trusting the URL. A sale of extra
 * checks: the row must exist, the viewer must be allowed to read it, and the
 * path rebuilt from the stored name must be the exact file on disk.
 */
function hr_document_serve(int $id, ?array $viewer, bool $download = false): void {
    $doc = hr_document($id);
    if ($doc === null || !hr_document_can_view($viewer, $doc)) { http_response_code(403); echo 'Not allowed.'; exit; }

    $email = (string) $doc['email'];
    $fileName = basename((string) $doc['file_name']);
    if ($fileName === '' || strpos($fileName, '.') === false) { http_response_code(404); exit; }

    $safe = preg_replace('/[^a-z0-9]/', '', strtolower((string) explode('@', $email)[0])) ?: 'emp';
    $dir = hr_docs_dir();
    $real = @realpath($dir . '/' . $safe . '-' . $fileName);
    $rdir = @realpath($dir);
    if ($real === false || $rdir === false
        || strpos($real, $rdir . DIRECTORY_SEPARATOR) !== 0
        || basename($real) !== $safe . '-' . $fileName
        || !is_file($real)) {
        http_response_code(404); exit;
    }

    $mime = (string) ($doc['mime'] ?? '');
    $disp = $download ? 'attachment' : 'inline';
    $label = (string) ($doc['orig_name'] ?? $fileName);
    $label = preg_replace('/[^A-Za-z0-9._ -]/', '_', $label) ?: $fileName;
    header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
    header('Content-Length: ' . (string) filesize($real));
    header('Content-Disposition: ' . $disp . '; filename="' . $label . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; object-src 'none'; sandbox");
    readfile($real);
    exit;
}
