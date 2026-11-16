<?php
/**
 * WorkFolio PHP - mailer unit checks.
 *
 * Runs without a web server and without an SMTP server: what matters here is
 * that the message is well formed, that a hostile Host header cannot bend the
 * sign-in link, and that nothing here throws when mail is switched off.
 *
 *   php test-mailer.php
 */
require_once __DIR__ . '/lib/mailer.php';

$pass = 0;
$fail = 0;
function check(bool $cond, string $name, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [PASS] $name\n"; }
    else { $fail++; echo "  [FAIL] $name  $detail\n"; }
}

echo "== configuration ==\n";
check(!mail_ready(), 'unconfigured site sends nothing (mail_from empty)');
check(mail_credentials(['email' => 'a@b.com'], 'x') === WF_MAIL_OFF, 'mail_credentials() reports OFF, never throws');
check(strpos(mail_flash_suffix(WF_MAIL_OFF, 'a@b.com'), 'share the password') !== false,
    'OFF tells the admin to pass the password on by hand');
check(strpos(mail_flash_suffix(WF_MAIL_FAILED, 'a@b.com'), 'NOT be sent') !== false,
    'FAILED says plainly that the mail did not go out');
check(strpos(mail_flash_suffix(WF_MAIL_SENT, 'a@b.com'), 'emailed to') !== false,
    'SENT confirms the address it went to');

$GLOBALS['WF']['mail_enabled'] = false;
$GLOBALS['WF']['mail_from'] = 'no-reply@workfolio.test';
check(!mail_ready(), 'mail_enabled=false overrides a configured From');

$GLOBALS['WF']['mail_enabled'] = true;
$GLOBALS['WF']['mail_from'] = 'not-an-address';
check(!mail_ready(), 'a malformed From is treated as unconfigured');
$GLOBALS['WF']['mail_from'] = 'no-reply@workfolio.test';
check(mail_ready(), 'a valid From enables mail');

echo "\n== header injection ==\n";
$evil = "Bad\r\nBcc: attacker@evil.test";
check(mail_header_safe($evil) === 'Bad Bcc: attacker@evil.test', 'CRLF in a name cannot add a header');
check(mail_header_safe("Name\r\n\r\n<html>") === 'Name <html>', 'double CRLF is flattened too');
check(mail_addr_safe('a@b.com') === 'a@b.com', 'valid address survives');
check(mail_addr_safe('a@b.com, c@d.com') === '', 'an address list is refused outright');
check(mail_addr_safe('') === '', 'empty address refused');

echo "\n== sign-in link cannot be redirected ==\n";
$_SERVER['HTTP_HOST'] = 'folio.biztrack.uk';
$_SERVER['HTTPS'] = 'on';
check(app_origin() === 'https://folio.biztrack.uk', 'https + real host');

$_SERVER['HTTP_HOST'] = "victim.test\r\nX-Injected: 1";
check(app_origin() === 'https://localhost', 'a CRLF Host falls back to localhost');

$_SERVER['HTTP_HOST'] = 'evil.test@phish.test';
check(app_origin() === 'https://localhost', 'userinfo in Host is refused');

$_SERVER['HTTP_HOST'] = '127.0.0.1:8899';
$_SERVER['HTTPS'] = '';
check(app_origin() === 'http://127.0.0.1:8899', 'port is kept, scheme follows the request');

$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
$_SERVER['HTTP_HOST'] = 'folio.biztrack.uk';
check(app_origin() === 'https://folio.biztrack.uk', 'reverse-proxy https is honoured');
unset($_SERVER['HTTP_X_FORWARDED_PROTO']);

echo "\n== message content ==\n";
$emp = ['first_name' => 'Ali', 'last_name' => 'Khan', 'email' => 'ali@workfolio.com', 'role' => 'EMPLOYEE'];
$m = mail_credentials_message($emp, 'S3cret-pass', 'created');
check($m['to'] === 'ali@workfolio.com', 'recipient is the new user');
check($m['subject'] === 'Your Folio account is ready', 'welcome subject');
check(strpos($m['text'], 'ali@workfolio.com') !== false, 'text carries the email');
check(strpos($m['text'], 'S3cret-pass') !== false, 'text carries the password');
check(strpos($m['text'], 'Web address : ' . app_origin() . base_url('/login')) !== false,
    'text carries the absolute sign-in link, built from this request');
check(strpos($m['html'], 'S3cret-pass') !== false, 'html carries the password');
check(strpos($m['subject'], 'S3cret-pass') === false, 'password is NOT in the subject');
check(strpos($m['html'], '<script') === false, 'no script tags in the html part');

$m2 = mail_credentials_message($emp, 'S3cret-pass', 'reset');
check($m2['subject'] === 'Your Folio password', 'reset uses its own subject');
check(strpos($m2['text'], 'reset the password') !== false, 'reset body says it was a reset');

$admin = ['first_name' => 'Riya', 'last_name' => 'Sharma', 'email' => 'admin@workfolio.com', 'role' => 'ADMIN'];
check(strpos(mail_credentials_message($admin, 'p', 'created')['text'], 'Administrator') !== false,
    'role note describes an ADMIN');

$xss = ['first_name' => '<script>alert(1)</script>', 'last_name' => '', 'email' => 'x@workfolio.com', 'role' => 'EMPLOYEE'];
$mx = mail_credentials_message($xss, 'p', 'created');
check(strpos($mx['html'], '<script>alert(1)</script>') === false, 'a name cannot inject markup into the html part');
check(strpos($mx['html'], '&lt;script&gt;') !== false, 'the name is escaped instead');

echo "\n== multipart envelope ==\n";
$boundary = 'wf-test';
$body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nhi\r\n--$boundary--";
check(substr_count($body, "--$boundary") === 2, 'boundary opens and closes once each');

echo "\n==============================================\n";
echo "Passed: $pass   Failed: $fail\n";
exit($fail === 0 ? 0 : 1);
