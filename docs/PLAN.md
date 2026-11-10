# Folio - Completion Record

Status: **complete and verified.** Server builds, starts, and all 14 admin/employee
pages render; the agent API passes 40/40 end-to-end checks; `WorkFolioAgent.exe`
compiles clean.

## What was added

| # | Requirement | Implementation |
|---|-------------|----------------|
| 1 | System activity recording | `MonitoringEngine` -> `POST /api/agent/telemetry` + `/api/agent/usage` |
| 2 | Live screenshots | `AgentConfig.liveStreamIntervalSeconds` -> frames tagged `LIVE`, small & frequent |
| 3 | Screenshots at a custom interval | `AgentConfig.screenshotIntervalSeconds` (10-7200s), pushed to the agent every cycle |
| 4 | Mouse + keyboard moves | `InputHooks` (`WH_MOUSE_LL` / `WH_KEYBOARD_LL`) -> `TrackRecord` counters |
| 5 | Clock in / out | `/api/agent/check-in` / `check-out`; agent buttons + tray menu |
| 6 | Break time | `/api/agent/break/start` / `break/end`; excluded from worked hours |
| 7 | Total working hours | `TrackRecord.workedSeconds()` = shift - break - idle |
| 8 | Apps & sites used, time on each | `AppUsage` rows; `distinctApps` / `distinctSites` counters; report bars + CSV |
| 9 | Idle time recording | `GetLastInputInfo` + admin-set threshold, split in `TrackService.settle()` |
| 10 | Desktop EXE for clock in/out/break | `agent/bin/WorkFolioAgent.exe` (59 KB, .NET Framework, no SDK needed) |
| 11 | Report to admin | `/admin/usage` + `/admin/usage.csv` + `/admin/usage-targets.csv` + print view |

## New server files
- `model/AppUsage.java`, `model/AgentConfig.java`
- `repository/AppUsageRepository.java`, `repository/AgentConfigRepository.java`
- `service/UsageService.java`, `service/ScreenshotStorage.java`
- `controller/UsageReportController.java` (report, CSV exports, agent settings, retention)
- `config/StorageMaintenance.java` (nightly retention sweep)
- `templates/portal/usage.html`, `templates/portal/agent-config.html`
- `templates/portal/monitor.html` rewritten (live grid + per-person drill-down)

## Changed server files
- `model/TrackRecord.java` - input counters, current app/site, distinct counts, agent identity
- `model/Screenshot.java` - disk-backed (`fileName`), capture `trigger`, app/title/host context
- `service/TrackService.java` - `recordTelemetry`, disk screenshots, attendance audit log
- `service/AgentAuthService.java` - `requireEmployee` helper
- `controller/api/AgentApiController.java` - `/config`, `/telemetry`, `/usage`, `/logout`
- `controller/api/MonitorApiController.java` - live feed, `/shot/{id}` image stream, `/usage`
- `config/SecurityConfig.java`, `config/DataSeeder.java`, `WorkfolioApplication.java`
- `application.properties` - file-backed H2, storage root, retention
- 8 templates - sidebar entries for **Activity Report** and **Agent Settings**

## Bugs found and fixed while verifying
1. `AgentConfig` had no `@Id` field - entity could not be mapped.
2. `AgentAuthService` was missing `requireEmployee`, used by the agent controllers.
3. `bodyEmployee` overloads clashed after type erasure - collapsed to `Map<String, ?>`.
4. H2 rejects `AUTO_SERVER=TRUE` together with `DB_CLOSE_ON_EXIT=FALSE`.
5. 24 Thymeleaf attributes mixed literal text with `${...}`, which cannot parse.
6. `/time` threw NPE when no `?email=` was given (pre-existing) - now defaults to the caller.
7. `portal/time.html` read `${summary.reportedActive}`, a key that never existed (pre-existing).
8. `employees.html` used a variable expression inside `th:onclick`, forbidden by Thymeleaf 3
   (pre-existing) - moved the message to `data-confirm`.
9. `ScreenshotRepository` derived query returned `List` where `Optional` was expected.
10. All `${currentUser.role...}` sidebar guards now null-safe with correct parenthesisation.

## Verified
- `mvnw.cmd package` -> BUILD SUCCESS, 32 sources, fat jar produced.
- Server starts in ~12-17s on port 8080.
- 14 pages HTTP 200: `/admin/usage`, `/admin/agent-config`, `/monitor`, `/dashboard`,
  `/portal/history`, `/time` (with and without `?email=`), `/employees`, `/admin/activity`,
  `/admin/reports`, `/profile`, `/portal/users`, plus filtered report variants.
- `agent/test-api.ps1` -> **40 passed, 0 failed**.
- Report HTML contains the recorded apps/sites; `usage-targets.csv` exports correct rows.
- Screenshots land in `data/screenshots/<date>/<email>/*.jpg`; DB is `data/db/workfolio.mv.db`.
- Data survives restarts (verified across three server restarts).
- `agent/build-agent.ps1` -> `WorkFolioAgent.exe`, 59 KB, no warnings.

## Build commands
```powershell
# server (needs JDK 8/11/17)
$env:JAVA_HOME = 'C:\path\to\jdk'
.\mvnw.cmd spring-boot:run

# agent (no SDK required)
powershell -ExecutionPolicy Bypass -File agent\build-agent.ps1

# end-to-end agent API check, server must be running
powershell -ExecutionPolicy Bypass -File agent\test-api.ps1
```

## Design notes
- **Config is pushed, not deployed** - the agent re-reads its policy every sampling
  cycle, so an admin changing the screenshot interval applies within seconds.
- **Trapezoid attribution** - time is credited to the window in the foreground
  *before* each tick, so alt-tabbing mid-interval is not misattributed.
- **Bounded samples** - one sample claims at most 120 s, so a rapidly flapping window
  cannot inflate a total.
- **Screenshots on disk** - the database stays small; the report streams JPEGs directly.
- **Offline tolerant** - the agent queues usage samples and screenshots to
  `%LOCALAPPDATA%\WorkFolioAgent` and replays them on reconnect.
- **Privacy** - hooks count events only; no key or character is ever read or sent.
  Excluded processes and blocked sites let admins leave windows unrecorded.

## Environment note
The `E:` volume dropped off the VM mid-session (`StorDiag 507`, SCSI I/O failure at
10:50). It was reattached and all files verified intact before work resumed.
