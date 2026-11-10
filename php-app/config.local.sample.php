<?php
/**
 * WorkFolio - database & server configuration for shared hosting.
 *
 * HOW TO USE
 * ----------
 * 1. Upload the whole "php-app" folder to your hosting (public_html or a
 *    sub-folder like /workfolio).
 * 2. Copy THIS file to "config.local.php" (same folder), then edit the values.
 *    The uploader on shared hosting can copy/rename files with the File Manager.
 * 3. Create a MySQL database in cPanel/your hosting panel (one database + one
 *    user, grant ALL privileges). The app creates its own tables on first use -
 *    there is nothing else to import.
 * 4. Log in with the first-run admin account and change its password;
 *    see README-upload.md.
 *
 * SQLite is still available for a quick local test: set db_driver = 'sqlite'
 * and the app stores everything in data/db.sqlite automatically.
 */
$WF['db_driver']   = 'mysql';           // 'mysql' (hosting) or 'sqlite' (local test)
$WF['db_host']     = 'localhost';       // usually localhost on shared hosting
$WF['db_port']     = '3306';
$WF['db_name']     = 'your_db_name';    // created in your hosting panel
$WF['db_user']     = 'your_db_user';
$WF['db_password'] = 'your_db_password';

/* Where screenshots are stored. Must stay web-inaccessible (data/ is blocked
   in .htaccess). Make sure the hosting user can write here. */
$WF['storage']     = __DIR__ . '/data';

/* How many days screenshots are kept before the automatic purge. */
$WF['retention']   = 14;

/* Server timezone for reports, e.g. 'Asia/Karachi', 'Asia/Kolkata', 'UTC'. */
$WF['timezone']    = '';

/* Daily working-time target the reports classify each day against, in hours.
   Defaults to 8, matching the desktop agent. Each day is shown as Below / On /
   Above target. Set to 0 or leave out to keep 8. */
$WF['workday_target_hours'] = 8;

/* ---------------------------------------------------------------------------
 * Emailing sign-in details
 *
 * When an administrator or HR creates a login (or resets a password), the app
 * emails the address and password to the new user so they can sign in
 * themselves. Nothing is sent until mail_from is filled in.
 *
 * mail_from MUST be an address on this site's own domain, e.g.
 * no-reply@folio.biztrack.uk if the app lives on folio.biztrack.uk. Shared
 * hosts reject -f for any other domain, and mail from an unowned domain is
 * dropped by SPF/DMARC at the recipient anyway. Create the mailbox (or a
 * forwarder to one) in cPanel first.
 *
 * Delivery uses the host's own PHP mail(), so there is no password or API key
 * to enter. If your host has mail() disabled, leave mail_from empty - the app
 * then shows the password on screen for you to pass on instead.
 * ------------------------------------------------------------------------ */
$WF['mail_from']      = '';              // e.g. no-reply@folio.biztrack.uk
$WF['mail_from_name'] = 'Folio';   // the "From" name people see
$WF['mail_enabled']   = true;             // false stops all outgoing mail

/* Set to true only for a demo/staging site - shows demo logins on the screen. */
$WF['show_demo']   = false;