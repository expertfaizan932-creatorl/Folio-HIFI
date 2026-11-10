<?php
/**
 * WorkFolio PHP - outgoing email.
 *
 * Shared hosting has no mail daemon of its own, so this uses the host's PHP
 * mail() (cPanel hands it to the account's authenticated SMTP relay). There is
 * no dependency to install and nothing to configure beyond a From address.
 *
 * It exists for one job: when an administrator or HR creates a login, the
 * person who owns it is emailed the address and password that were just set, so
 * they can sign in themselves instead of being handed a note.
 *
 * Nothing in here throws. A mail server that is down, a From address the host
 * refuses and a recipient the server rejects all have to leave the account that
 * was just created intact - the caller falls back to showing the credentials on
 * screen for a human to pass on.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';   // employee_full_name() for the greeting

/** Status strings, so the caller can say something useful either way. */
define('WF_MAIL_SENT', 'sent');
define('WF_MAIL_OFF', 'off');
define('WF_MAIL_FAILED', 'failed');

/**
 * The bare From address, or '' when mail cannot be sent.
 *
 * Empty unless configured, and deliberately not guessed from the database: a
 * From on a domain this site does not own fails SPF/DMARC and lands in spam or
 * is rejected outright, which looks identical to a broken feature.
 */
function mail_from_addr(): string {
    global $WF;
    if (empty($WF['mail_enabled'])) return '';
    $from = trim((string) ($WF['mail_from'] ?? ''));
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) return '';
    return $from;
}

function mail_ready(): bool {
    return mail_from_addr() !== '';
}

/**
 * Strips anything that could add a header of its own, and rejects addresses
 * that are not addresses. Every value below reaches a mail header or a header
 * parameter, so a newline in a person's name would otherwise let whoever
 * controls that field choose the recipient.
 */
function mail_header_safe(?string $value): string {
    $v = trim(preg_replace('/[\r\n\t]+/', ' ', (string) $value));
    return trim(str_replace(['\\', '"'], '', $v));
}

function mail_addr_safe(string $email): string {
    $e = mail_header_safe($email);
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
}

/**
 * Sends one multipart/alternative message. Returns true only when the MTA
 * accepted it - that is not a delivery receipt, but it is the difference
 * between "handed over" and "refused", and only the second is worth reporting.
 */
function mail_send(string $to, string $subject, string $text, string $html): bool {
    $to = mail_addr_safe($to);
    $from = mail_from_addr();
    if ($to === '' || $from === '') return false;

    $subject = mail_header_safe($subject);
    $fromName = mail_header_safe($GLOBALS['WF']['mail_from_name'] ?? '');
    $boundary = 'wf-' . bin2hex(random_bytes(12));

    $headers = [
        'MIME-Version: 1.0',
        'From: ' . ($fromName === '' ? $from : $fromName . ' <' . $from . '>'),
        'Reply-To: ' . $from,
        'X-Mailer: WorkFolio',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];

    $body = "--$boundary\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . str_replace("\n", "\r\n", $text) . "\r\n"
        . "--$boundary\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . str_replace("\n", "\r\n", $html) . "\r\n"
        . "--$boundary--";

    /* The envelope sender is a bare address: mail() passes it to sendmail's -f,
     * and hosts reject a "Name <a@b.c>" there. Suppressed because a refused
     * mail is handled below, not printed into the page the admin is looking at. */
    $sent = @mail($to, $subject, $body, implode("\r\n", $headers), '-f' . $from);
    return $sent === true;
}

/** What a role can reach, so the mail says something useful about the account. */
function mail_role_note(?string $role): string {
    switch ($role) {
        case 'SUPER_ADMIN': return 'Owner - full access to every account and report. You are not tracked and do not appear as a monitored user.';
        case 'ADMIN': return 'Administrator - full access, including credentials, agent settings and the audit log.';
        case 'HR':     return 'HR - employee records, reports, live monitor and screenshots.';
        default:       return 'Employee - your own time records, screenshots and history.';
    }
}

/**
 * Builds the welcome/reset message for a login. Returns to/subject/text/html.
 *
 * Split out from mail_credentials() so the wording and the escaping can be
 * checked without an SMTP server - the interesting part of this file is not the
 * hand-off to mail(), it is what ends up in the body.
 */
function mail_credentials_message(array $employee, string $password, string $kind = 'created'): array {
    $to = mail_addr_safe((string) ($employee['email'] ?? ''));
    $name = employee_full_name($employee);
    if ($name === '') $name = $to;
    $role = (string) ($employee['role'] ?? 'EMPLOYEE');
    $loginUrl = app_origin() . base_url('/login');

    $intro = $kind === 'reset'
        ? 'An administrator has reset the password on your Folio account.'
        : 'An administrator has created your Folio account.';

    $text = $intro . "\n\n"
        . "Hello " . $name . ",\n\n"
        . "You can sign in with these details:\n\n"
        . "  Web address : " . $loginUrl . "\n"
        . "  Email       : " . $to . "\n"
        . "  Password    : " . $password . "\n\n"
        . "Your access level: " . mail_role_note($role) . "\n\n"
        . "The desktop agent on your PC uses this same email address and\n"
        . "password, so keep them somewhere safe.\n\n"
        . "If you would like a different password, ask an administrator to\n"
        . "reset it - there is no self-service change yet.\n\n"
        . "This message was sent automatically. Please do not reply to it.\n";

    $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $html = '<div style="font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;color:#1f2430;line-height:1.55;max-width:560px">'
        . '<p style="margin:0 0 4px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#7237f5;font-weight:600">Folio</p>'
        . '<h1 style="margin:0 0 16px;font-size:21px">' . $e($intro) . '</h1>'
        . '<p style="margin:0 0 16px">Hello ' . $e($name) . ',</p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0 0 20px">'
        . '<tr><td style="padding:6px 16px 6px 0;color:#6b7280">Web address</td>'
        . '<td style="padding:6px 0"><a href="' . $e($loginUrl) . '" style="color:#7237f5">' . $e($loginUrl) . '</a></td></tr>'
        . '<tr><td style="padding:6px 16px 6px 0;color:#6b7280">Email</td>'
        . '<td style="padding:6px 0">' . $e($to) . '</td></tr>'
        . '<tr><td style="padding:6px 16px 6px 0;color:#6b7280">Password</td>'
        . '<td style="padding:6px 0;font-family:Consolas,Menlo,monospace;background:#fdecec;padding:6px 10px;border-radius:4px;display:inline-block">'
        . $e($password) . '</td></tr>'
        . '</table>'
        . '<p style="margin:0 0 16px;color:#4b5563">Your access level: ' . $e(mail_role_note($role)) . '</p>'
        . '<p style="margin:0 0 16px">The desktop agent on your PC uses this same email address and password, so keep them somewhere safe.</p>'
        . '<p style="margin:0 0 16px">If you would like a different password, ask an administrator to reset it - there is no self-service change yet.</p>'
        . '<hr style="border:0;border-top:1px solid #e5e7eb;margin:20px 0">'
        . '<p style="margin:0;font-size:12px;color:#9ca3af">Sent automatically by Folio. Please do not reply to this message.</p>'
        . '</div>';

    $subject = $kind === 'reset'
        ? 'Your Folio password'
        : 'Your Folio account is ready';

    /* The password is deliberately kept out of the subject: subjects are kept
     * by mail servers, list managers and search indexes long after the body
     * is gone. */
    return [
        'to'      => $to,
        'subject' => $subject,
        'text'    => $text,
        'html'    => $html,
    ];
}

/**
 * Mails a freshly set login to the person it belongs to.
 *
 * $kind is the reason, shown in the subject so a later reset is not mistaken
 * for the original welcome. Returns one of the WF_MAIL_* statuses.
 */
function mail_credentials(array $employee, string $password, string $kind = 'created'): string {
    if (!mail_ready()) return WF_MAIL_OFF;

    $m = mail_credentials_message($employee, $password, $kind);
    if ($m['to'] === '') return WF_MAIL_FAILED;

    return mail_send($m['to'], $m['subject'], $m['text'], $m['html']) ? WF_MAIL_SENT : WF_MAIL_FAILED;
}

/**
 * Turns a mail status into the sentence the administrator sees. Split out so
 * every create/reset path tells the same story: when it went out, and what to
 * do when it did not.
 */
function mail_flash_suffix(string $status, string $email): string {
    switch ($status) {
        case WF_MAIL_SENT:
            return ' The sign-in details were emailed to ' . $email . '.';
        case WF_MAIL_FAILED:
            return ' The email to ' . $email . ' could NOT be sent - share the password above yourself.';
        default:
            return ' No email was sent (no From address configured in config.local.php) - share the password above yourself.';
    }
}
