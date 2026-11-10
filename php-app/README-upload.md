# Uploading Folio (PHP) to shared hosting

Folio now runs on cheap shared **PHP hosting** — no VPS, no Java, no Docker.
This folder (`php-app/`) is the whole application. You upload it, set one config
file, and log in. The app creates its database tables automatically on the first
request.

> Your Windows agent (`WorkFolioAgent.exe`) does **not** change. After the site is
> live you only rebuild the USB package with the new server URL
> (step 5 below).

---

## 1. What you need from your hosting

Any cPanel / Plesk / shared host with:

- **PHP 7.4 or newer** (8.x is best — any recent host has it)
- **MySQL** (a database + user, created in cPanel or similar)
- The ability to make one folder writable (usually automatic)

If your host is Nginx instead of Apache, also read the note at the very end.

---

## 2. Upload the files

1. Zip this whole `php-app` folder on your PC.
2. Open cPanel (or the host's file manager) → open the web root
   (`public_html` on most hosts).
3. Upload the zip and extract it there.
   - **Option A (domain root):** files go straight into `public_html/`.
   - **Option B (sub-folder):** files go into `public_html/workfolio/` and you
     visit `https://yourdomain.com/workfolio/`. Everything (links, login, agent
     API, PWA) works in the sub-folder too — it is detected automatically.
4. Make sure the hidden `.htaccess` file was uploaded (in cPanel File Manager
   click **Settings → Show hidden files** if you don't see it). It must sit
   next to `index.php`.

## 3. Configure the database

1. In cPanel → **MySQL Databases**, create a database and a database user,
   grant the user ALL privileges on that database.
2. In the uploaded folder, copy `config.local.sample.php` to
   `config.local.php` (File Manager → Copy, or make a new file and paste).
3. Open `config.local.php` and fill in:

   ```php
   $WF['db_driver']   = 'mysql';
   $WF['db_name']     = 'your_db_name';      // from step 1
   $WF['db_user']     = 'your_db_user';      // from step 1
   $WF['db_password'] = 'the_password';      // from step 1
   ```

   `db_host` is `localhost` on nearly every shared host — leave it.
4. Save. **There is no SQL import** — the tables are created on first use.

## 4. First login + change passwords

1. Visit your site (e.g. `https://yourdomain.com/` or
   `https://yourdomain.com/workfolio/`).
2. Log in with the first-run admin:

   | Email | Password | Role |
   |---|---|---|
   | `admin@workfolio.com` | `admin123` | ADMIN |
   | `hr@workfolio.com` | `hr123` | HR |
   | `rahul@workfolio.com` | `emp123` | EMPLOYEE |
   | `sneha@workfolio.com` | `emp123` | EMPLOYEE |

3. Go to **Credentials** → change the admin password and the others'
   passwords right away. Delete the demo accounts you don't need from
   **Employees**.

## 5. Point the agent EXE at the live site

On your PC, rebuild the USB package (this bakes the live `ServerUrl` into the
agent):

```powershell
.\build-usb.ps1 -ServerUrl "https://yourdomain.com"
```

(If you installed in a sub-folder, use
`.\build-usb.ps1 -ServerUrl "https://yourdomain.com/workfolio"`.)

Copy the fresh USB package to each employee's machine and run
`WorkFolioAgent.exe`. It logs in, checks in, and starts sending telemetry,
usage and screenshots to the live PHP server — same as the old one.

---

## Daily things you may want

- **Screenshot retention:** kept for `$WF['retention']` days (default 14).
  The **Agent Settings** page has a manual *Purge* button too. On shared
  hosting the app runs its own daily purge on incoming traffic (no cron).
- **Agent policy per employee:** **Agent Settings** (screenshot interval,
  image quality, sample rate, which apps/sites to exclude…).
- **Daily working-time target:** reports classify each day against 8 hours by
  default (Below / On / Above target), on the History, Activity Report and
  Company Reports pages, for HR, Admin and Super Admin. Change it with
  `$WF['workday_target_hours']` in `config.local.php` (or the
  `WF_WORKDAY_TARGET_HOURS` environment variable). The desktop agent uses the
  same 8-hour default, so the two always agree.
- **Permissions recap:** ADMIN sees everything; HR sees EMPLOYEE-role people
  plus themselves; EMPLOYEE sees only their own record. Screenshot links and
  `?email=` parameters are checked against this rule on every request.

---

## Updating an already-live site

Upload only the changed files, overwriting the old ones. For the 8-hour target
and server-clock changes, that is:

- `lib/core.php` (adds the `workday_target_hours` setting and the version)
- `lib/tracks.php` (the target classification helpers)
- `lib/pages.php` (the Target columns on the reports)
- `lib/api_agent.php` (the `serverTime` field the agent uses for clock drift)
- `config.local.sample.php` (documentation only; your live `config.local.php`
  is never overwritten)

Then reload any report page once. **Do not** replace `config.local.php` or the
`data/` folder, or the live database settings and screenshots are lost.

---

## Troubleshooting

| Problem | Fix |
|---|---|
| Blank page or "Database setup failed" | Check `config.local.php` DB name/user/password; confirm the DB exists in cPanel. |
| "data" folder not writable | Most hosts are fine; if not, set `data/` permissions to 755/775 or use cPanel → File Manager → it's safe for screenshots to be web-blocked (the `data/` folder is denied in `.htaccess`). |
| Login works but pages 404 | The `.htaccess` file was not uploaded (hidden files). Re-upload it. |
| Screenshots never appear | Check the agent policy (Agent Settings) — if `captureScreenshots` is unchecked the agent stops sending captures. |
| Agent says "login failed" | You changed the password but the agent still uses the old USB package → rebuild with `build-usb.ps1`. |
| Date/time looks wrong | Set `$WF['timezone']` in `config.local.php`, e.g. `'Asia/Kolkata'`. |

---

## Nginx hosts only

If the host runs **Nginx** (no `.htaccess`), ask them to add these two rules
for your folder (they replace the whole `.htaccess`):

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
location ~ ^/data/ { deny all; }
```

Everything else (PHP handler, MySQL) is the same.