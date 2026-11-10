# Folio

Employee management portal with a **desktop activity agent**, live screen
monitoring and a full activity & usage report.

Two parts:

- **Server** - support for **two** backends that speak the identical API:
  - **PHP** (`php-app/`) - runs on cheap shared PHP hosting (cPanel, Plesk,
    most budget hosts). This is the recommended deployment: upload files, set
    one config file, done.
  - **Spring Boot 2.7** (`src/main/java`) - the original Java server, for a VPS
    or XAMPP when you prefer it.
- **Agent** - `WorkFolioAgent.exe`, a Windows desktop app (`agent/`) that employees
  run to clock in/out, take breaks, and record what they work on.
- **Phone app** - `FolioManager.apk` (`android/`), a WebView of the live site plus
  a background service that lets the owner run commands, open a terminal and
  browse files on an employee's phone - the mobile counterpart of the desktop
  agent, driven by `WorkFolioApkManager.exe`.

The agent and the reports UI talk to the PHP and Java servers **identically** —
switching backends never touches the EXE or the browser screens.

---

## Feature coverage

| Requirement | Where it lives |
|---|---|
| System activity recording | `agent/src/MonitoringEngine.cs` → `POST /api/agent/telemetry`, `POST /api/agent/usage` |
| Employee time-tracker panel | `agent/src/MainForm.cs` → `GET /api/agent/day` (shift timeline, totals, break allowance) |
| **Live** screenshots | `liveStreamIntervalSeconds` in Agent Settings; `AgentConfig`, `Live` trigger |
| Screenshots after a **custom time period** | `screenshotIntervalSeconds`, per employee, pushed to the agent every cycle |
| **Mouse and keyboard** moves | `agent/src/InputHooks.cs` (`WH_MOUSE_LL` / `WH_KEYBOARD_LL`) → `TrackRecord` counters |
| Clock in / clock out | `POST /api/agent/check-in` / `check-out`; agent buttons + tray menu |
| Break time | `POST /api/agent/break/start` / `break/end`; excluded from working hours |
| Total working hours | `TrackRecord.workedSeconds()` = shift − break − idle |
| **How many apps/sites used** | `AppUsage` rows, `distinctApps` / `distinctSites` on `TrackRecord` |
| **How much time on each** | `AppUsage.seconds` per target; report bars and CSV |
| **Idle time recording** | `GetLastInputInfo` + `idleThresholdSeconds` → `TrackService.settle()` |
| **EXE for clock in/out/break** | `agent/bin/WorkFolioAgent.exe` |
| **Send each report to admin** | `/admin/usage` page + `/admin/usage.csv` + `/admin/usage-targets.csv` |

---

## Running the server

Requires JDK 8, 11 or 17.

```powershell
$env:JAVA_HOME = 'C:\path\to\jdk'
.\mvnw.cmd spring-boot:run
```

Open <http://localhost:8080/login>.

| Role | Email | Password |
|---|---|---|
| ADMIN | `admin@workfolio.com` | `admin123` |
| HR | `hr@workfolio.com` | `hr123` |
| Employee | `rahul@workfolio.com` | `emp123` |
| Employee | `sneha@workfolio.com` | `emp123` |

These accounts are seeded only when the database is empty, and the login screen no
longer advertises their passwords. Set `workfolio.demo.show-accounts=true` in
`application.properties` to print the table above on the login page again for a
demonstration.

The database is file-backed H2 (`./data/db/workfolio`) so history, usage totals and
reports survive restarts. Screenshots are written as JPEG files under
`./data/screenshots/<date>/<email>/` and pruned nightly.

The H2 console is at `/h2-console` and now requires a signed-in ADMIN. It is
disabled entirely outside the default profile.

### Running on MySQL instead (XAMPP)

The default profile is H2 so nothing needs installing. To use MySQL — XAMPP on
your machine, MySQL 8 on a server — switch to the `mysql` profile:

```powershell
$env:SPRING_PROFILES_ACTIVE = 'mysql'
$env:DB_USER     = 'workfolio'
$env:DB_PASSWORD = 'your-password'
.\mvnw.cmd spring-boot:run
```

Create the database once (Hibernate builds the tables itself):

```sql
CREATE DATABASE workfolio CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'workfolio'@'localhost' IDENTIFIED BY 'your-password';
GRANT ALL PRIVILEGES ON workfolio.* TO 'workfolio'@'localhost';
```

Connection settings all come from the environment — `DB_HOST`, `DB_PORT`,
`DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_PARAMS` — so the same jar runs against
XAMPP and against a production server without a rebuild.

---

## Putting it on the internet

The server is the only thing that needs hosting; the agent already talks to it
over HTTPS and pulls its policy from it. The admin/HR reports are an installable
PWA, so there is no second EXE to build or distribute.

### Option 1 - PHP shared hosting (recommended)

The whole app is the `php-app/` folder. Upload it to any budget PHP host
(cPanel / Plesk, PHP 7.4+, MySQL), copy `config.local.sample.php` to
`config.local.php` with your database name/user/password, and open the site.
There is no SQL import and no build step. Works from a domain root **or** a
sub-folder.

The step-by-step guide is [`php-app/README-upload.md`](php-app/README-upload.md)
(screenshots/database folder, first login, agent pointer, trouble-shooting and
the Nginx-only rules). A local end-to-end test that requires nothing but PHP is
[`php-app/test-local.ps1`](php-app/test-local.ps1).

### Option 2 - Docker + VPS (the original Java server)

```bash
cp .env.example .env      # set DOMAIN, ACME_EMAIL, and both passwords
docker compose up -d --build
```

Caddy obtains the TLS certificate automatically. Full walkthrough, firewall
rules, backups, the employee rollout and the go-live checklist are in
[`docs/DEPLOY.md`](docs/DEPLOY.md).

---

## Building the agent

Uses the C# compiler built into the .NET Framework - no Visual Studio or .NET SDK.

```powershell
powershell -ExecutionPolicy Bypass -File agent\build-agent.ps1
```

Produces `agent/bin/WorkFolioAgent.exe` (~60 KB, no runtime install required).
See `agent/README.md` for the deployment, autostart and privacy notes.

The owner-side remote consoles are built the same way:

```powershell
powershell -ExecutionPolicy Bypass -File agent\build-manager.ps1     # WorkFolioManager.exe    (PCs + phones)
powershell -ExecutionPolicy Bypass -File agent\build-apkmanager.ps1  # WorkFolioApkManager.exe (phones only)
```

---

## Building the phone app (Folio Manager APK)

The Android app (`android/`) is a WebView of the live site plus a foreground
service the owner drives from the same remote consoles. It is framework-only
(no AndroidX, no third-party libraries) and builds to a signed release APK:

```powershell
powershell -ExecutionPolicy Bypass -File android\build-apk.ps1
```

Output: `dist\FolioManager.apk` (install with `adb install -r dist\FolioManager.apk`).
Signing uses `android\folimanager-release.keystore`. See
[`android/README.md`](android/README.md) for the setup, permissions and the
Android sandbox limitations.

---

## Distributing the agent from a USB stick

`build-usb.ps1` packs everything an employee needs onto one USB: the agent EXE
(pre-filled with your server URL), an `INSTALL.bat` that copies it to that PC and
registers it to start with Windows, `INSTALL-SILENT.bat`, `RUN.bat` for trying it
without installing, `UNINSTALL.bat`, and a short bilingual setup guide.

```powershell
powershell -ExecutionPolicy Bypass -File build-usb.ps1 -ServerUrl "https://workfolio.mycompany.com"
```

Copy the `usb/` folder to a stick and hand it out. The registry autostart entry
points at `%LOCALAPPDATA%\WorkFolioAgent\`, **not** at the USB path, so the agent
keeps working after the stick is removed. Rebuild the package for each company —
the URL in `agent.config` is the only per-company setting.

---

## Admin screens

| Screen | Path | What it shows |
|---|---|---|
| **Activity Report** | `/admin/usage` | Working / active / idle / break totals, productivity, per-employee breakdown, day-by-day, apps and sites with time bars, screenshot gallery, CSV + print export |
| **Agent Settings** | `/admin/agent-config` | Per-employee screenshot interval, live-stream rate, idle threshold, capture quality, tracking switches, excluded processes, blocked sites, retention purge |
| **Live Monitor** | `/monitor` | Live screenshot grid with duty/idle/break state, current app and site; click a card for that person's app/site breakdown |
| History | `/portal/history` | Day-by-day shift records with manual corrections |
| Activity Tracker | `/admin/activity` | Web-application audit log (page views, logins, CRUD) |

### Report visibility (who sees what)

Every screen that renders or exports per-employee data is scoped by role, server
side. The rule lives in one class, `ReportAccessService`, and applies to the
employee dropdown, every `?email=` parameter, the CSV exports, the live monitor
grid, the drill-down API **and** screenshots looked up by id:

| Report of person… | ADMIN | HR | EMPLOYEE |
|---|---|---|---|
| EMPLOYEE | ✅ | ✅ | ❌ |
| HR | ✅ | ❌ (only own) | ❌ |
| ADMIN | ✅ | ❌ | ❌ |
| yourself | ✅ | ✅ | ✅ |

Consequences that may not be obvious:

- An HR cannot see another HR's or the admin's rows in the report, the live
  grid, or a screenshot — even by guessing a screenshot id (`403`).
- `?email=` parameters for someone you may not see are ignored: the page shows
  the broadest view you are allowed instead of leaking the target.
- `/admin/agent-config` save is scoped too, so HR cannot rewrite the admin's
  monitoring policy.

---

## Installing the reports as an app

Every screen is a PWA. In Edge or Chrome, sign in and click **Install app** in
the bottom-right: the reports then open in a standalone window with their own
taskbar entry and no address bar, and they update themselves from the server.
`manifest.webmanifest` is served with its content type pinned by
`PwaController`, because Tomcat 9 has no default MIME mapping for `.webmanifest`
and Chrome rejects the install prompt without it. Icons come from
`build-icons.ps1`.

---

## How the data flows

```
WorkFolioAgent.exe
   │  every N seconds (admin-set sample interval)
   ├─ foreground window ──────────► /api/agent/usage       → AppUsage.seconds
   ├─ keystrokes/clicks/scrolls ─► /api/agent/telemetry   → TrackRecord counters
   ├─ active/idle heartbeat ─────►   (same call)
   └─ screenshot (auto/live/manual) ► /api/agent/screenshot → data/screenshots/*.jpg
                                        │
                                        ▼
                              TrackService.settle()
                     elapsed time split into active vs idle,
                     break excluded from worked hours
                                        │
                                        ▼
              /admin/usage  ·  /monitor  ·  CSV export
```

Key design points:

- **Trapezoid attribution** - time is credited to the window that was in the
  foreground *before* each tick, so alt-tabbing mid-interval is not misattributed.
- **Bounded samples** - one sample can claim at most 120 s, so a flapping window
  cannot inflate a total.
- **Config is pushed, not deployed** - the agent fetches its policy every cycle, so
  an admin changing the screenshot interval takes effect immediately.
- **Never loses work time** - the agent queues usage samples and screenshots to disk
  when the server is unreachable and replays them on reconnect.
- **Storage stays small** - screenshots go to disk, not into the database.

---

## Privacy

The keyboard and mouse hooks **count events only**. No key code, character or typed
text is read, logged, stored or transmitted. Administrators can also switch off
screenshots, input tracking, app tracking or website tracking per employee, and can
exclude processes or block hosts so those windows are never recorded.

Monitoring of this kind should be disclosed to staff and run in line with local
labour law.

---

## Project layout

```
src/main/java/com/workfolio/
  config/      SecurityConfig, DataSeeder, ActivityInterceptor, StorageMaintenance,
               PwaController (manifest content type)
  controller/  PortalController, EmployeeController, DashboardController,
               UsageReportController (activity report + CSV + agent settings)
    api/       AgentApiController (agent endpoints), MonitorApiController (live feed)
  model/       Employee, ActivityLog, TrackRecord, Screenshot, AppUsage, AgentConfig
  repository/  JPA repositories
  service/     EmployeeService, ActivityService, TrackService, UsageService,
               ReportAccessService (role-scoped report visibility),
               ScreenshotStorage, AgentAuthService
src/main/resources/
  application.properties        H2 default profile
  application-mysql.properties  MySQL profile (XAMPP / server)
  application-prod.properties   hardening, always paired with mysql
  static/       manifest.webmanifest, sw.js, js/pwa.js, icons/
  templates/
                portal/usage.html, portal/agent-config.html, portal/monitor.html,
                portal/time.html, portal/history.html, employees/, activity.html, ...
                fragments/pwa.html  (PWA head tags + install button)
agent/
  src/         12 C# sources for the desktop agent
  bin/         WorkFolioAgent.exe
  build-agent.ps1, test-api.ps1, README.md
  manager/     ManagerGui.cs - the owner remote console (WorkFolioManager.exe
               and WorkFolioApkManager.exe share it; APKMANAGER filters to phones)
  build-manager.ps1, build-apkmanager.ps1
android/       Folio Manager phone app (WebView + background command service)
               build-apk.ps1, keystore.properties, app/src/main/java/... , README.md
build-icons.ps1   regenerates the PWA icon set (no image editor needed)
build-usb.ps1     assembles the hand-out USB package into usb/
php-app/          the PHP server for cheap shared hosting (see README-upload.md)
                  lib/core.php schema+bootstrap, auth, tracks, usage, shots,
                  api_agent (13 agent endpoints), api_monitor, pages, views
Dockerfile, docker-compose.yml, Caddyfile, .env.example   Java server deployment
docs/PLAN.md   feature gap analysis and build notes
docs/DEPLOY.md full deployment, backup and go-live guide
```

---

## Verifying the agent pipeline

With the server running, `agent/test-api.ps1` exercises the whole agent API -
sign in, clock in, break, telemetry, app and website samples, screenshot upload and
streaming, report queries, authorisation, clock out:

```powershell
powershell -ExecutionPolicy Bypass -File agent\test-api.ps1
```
