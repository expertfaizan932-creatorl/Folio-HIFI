# Deploying Folio

How to take this system off your machine and put it on the internet.

---

## 1. What actually has to be hosted

Only the **server**. Everything else already exists:

| Piece | Where it runs | What you do |
|---|---|---|
| `WorkFolio-0.0.1-SNAPSHOT.jar` | a server on the internet | deploy it once |
| `WorkFolioAgent.exe` (64 KB) | each employee's Windows PC | copy it over, they enter your public URL once |
| Admin & HR reports | their own PC | open your URL, click **Install app** |

The agent pulls its whole policy from the server every cycle, so you change
screenshot intervals, retention or which employees are tracked **from the admin
UI** — never by re-deploying the EXE.

The agent is Windows-only (WinForms, low-level input hooks, UI Automation). The
server runs on Linux just fine.

---

## 2. Profiles

Three Spring profiles, chosen by `SPRING_PROFILES_ACTIVE`:

| Profile | Database | Use for |
|---|---|---|
| *(default)* | H2 file, `./data/db` | your machine, zero setup |
| `mysql` | MySQL / MariaDB | XAMPP locally, or a real server |
| `prod` | *(none — hardening only)* | always pair with `mysql` |

`prod` does not pick a database. It tightens the app: no stack traces, secure
session cookies, template caching, short screenshot retention.

### Running on your machine with XAMPP

Start MySQL in the XAMPP control panel, then create the database once:

```sql
CREATE DATABASE workfolio CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'workfolio'@'localhost' IDENTIFIED BY 'pick-a-password';
GRANT ALL PRIVILEGES ON workfolio.* TO 'workfolio'@'localhost';
FLUSH PRIVILEGES;
```

Hibernate creates the tables on first start, so nothing else is needed.

```powershell
$env:SPRING_PROFILES_ACTIVE = 'mysql'
$env:DB_USER     = 'workfolio'
$env:DB_PASSWORD = 'pick-a-password'
.\mvnw.cmd spring-boot:run
```

Connection settings are all environment driven (`DB_HOST`, `DB_PORT`, `DB_NAME`,
`DB_USER`, `DB_PASSWORD`, `DB_PARAMS`), which is why the same jar runs against
XAMPP and against a production server with no rebuild.

---

## 3. Deploying to a server (recommended)

Anything that runs Docker works. A 2 vCPU / 4 GB / 80 GB VPS is enough for
roughly 25-50 employees.

```bash
# on the server
git clone <your-repo> workfolio && cd workfolio
cp .env.example .env
nano .env                    # set DOMAIN, ACME_EMAIL, both passwords
docker compose up -d --build
```

Then point your domain's **A record** at the server and wait a minute — Caddy
requests a Let's Encrypt certificate on first start and renews it forever
afterwards. Nothing to renew by hand.

```bash
docker compose ps             # app should be 'healthy'
docker compose logs -f app
```

The stack is three containers:

- **db** — MySQL 8, not published to the host, only reachable inside the compose network
- **app** — the jar, unprivileged user, `/app/data` as a named volume
- **caddy** — TLS on `:443`, reverse proxy to `app:8080`

### Ports and firewall

| Port | Who needs it |
|---|---|
| 22 | you, for SSH |
| 80, 443 | the internet, for HTTPS (certs validate over 80) |
| 8080, 3306 | **nobody** — never open these |

The app binds to 8080 inside the container and is not published at all, so there
is no way to reach the H2 console or the database from outside.

---

## 4. Rolling out to employees

1. Copy `agent\bin\WorkFolioAgent.exe` to each machine.
2. On first run they enter your public URL (`https://workfolio.example.com`),
   their work email, and their password.
3. Optionally run `WorkFolioAgent.exe --install-autostart` so it starts with
   Windows and sits in the notification area.

To make the URL pre-filled for everyone, edit the default in
`agent/src/AgentSettings.cs` (`ServerUrl`), rebuild with
`agent\build-agent.ps1`, and distribute that EXE.

Because the URL is stored in `agent.config` next to the EXE, you can also just
edit that one line on a machine instead of rebuilding.

---

## 5. Admin and HR: installing the reports as an app

Reports are installable as a **PWA** — a standalone window with its own taskbar
entry and no address bar. There is no separate admin EXE to build, distribute or
update.

1. Open your public URL in **Edge** or **Chrome** (Firefox and iOS Safari do not
   support install prompts; they can bookmark it instead).
2. Sign in as ADMIN or HR.
3. Click the **Install app** button in the bottom-right — it only appears once
   the browser has decided your site qualifies.
4. The app opens as its own window. Right-click the icon → **Pin to taskbar**.

Updates come from the server, so a deploy is all it takes to push changes out.

---

## 6. Before you go live — checklist

- [ ] **Change every seeded password.** `admin@workfolio.com` / `admin123`,
      `hr@workfolio.com` / `hr123`, and the two employee accounts are in the
      README and are re-seeded whenever the database is empty.
- [ ] **TLS is on.** The agent uploads screenshots and the report shows salary
      data. Verify `https://` in the browser before handing out the URL.
- [ ] **Screenshots have a short retention.** `WORKFOLIO_RETENTION_DAYS`
      defaults to 14 in prod. Screenshots are the most sensitive records here.
- [ ] **Seed no new accounts by accident.** `workfolio.demo.show-accounts` is
      `false` in prod, so the login page no longer advertises credentials.
- [ ] **Backups are running** (see below).
- [ ] **Staff have been told**, in writing, what is recorded. Employee
      monitoring needs disclosure and must fit local labour law. The excluded
      processes and blocked sites lists exist so personal windows stay
      unrecorded — set them up before you roll out, not after a complaint.

---

## 7. Backups

Two things need backing up, and they are in different places:

| Data | Where | Command |
|---|---|---|
| Database (reports, employees, totals) | the `db-data` volume | `docker compose exec db mysqldump -u root -p workfolio > backup.sql` |
| Screenshot archive | the `app-data` volume | `docker run --rm -v workfolio_app-data:/data -v $PWD:/out alpine tar czf /out/screenshots.tgz -C /data .` |

A nightly cron doing both is enough. Restoring the database is just
`mysql workfolio < backup.sql` — Hibernate will have created the schema, so a
restore into an empty database works.

---

## 8. Troubleshooting

| Symptom | Cause |
|---|---|
| Agent says it cannot reach the server | URL missing `https://`, or the agent is on a network that blocks the port. Check `agent.config`. |
| Caddy will not get a certificate | the A record does not resolve to this server yet, or port 80 is closed. `docker compose logs caddy` |
| No "Install app" button | must be HTTPS (or localhost), and needs Edge/Chrome. Check the browser console for a manifest error. |
| `Access denied for user 'workfolio'` | `DB_PASSWORD` in `.env` does not match the password the MySQL user was created with. |
| `ddl-auto` leaves a table half-created | drop the database and restart; Hibernate rebuilds it from the entities. |
| Report totals look wrong after a DB change | the seeded demo data is still there. Clear it from `/admin/activity` or start from an empty database. |

---

## 9. Running several companies

The schema has no tenant column — `Employee` has no `companyId` — so a single
deployment serves exactly one company. Giving each client its own container
stack on its own VPS is simple, isolated, and needs no code change. That is the
right shape until the multi-tenant refactor is genuinely worth paying for.
