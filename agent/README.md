# Folio Agent (Windows desktop app)

The employee-side executable. It runs in the notification area, records what is
happening on the machine, and uploads it to the Folio server, which rolls it
up into the admin's activity report.

## What it records

| Signal | How it is collected |
|---|---|
| Clock in / clock out | Buttons in the agent, posted to `/api/agent/check-in` / `check-out` |
| Break time | Start / End Break buttons, posted to `/api/agent/break/*` |
| Total working hours | Derived server-side: check-in → check-out minus break and idle |
| Idle time | `GetLastInputInfo`; idle after the admin's threshold with no input |
| Keyboard activity | `WH_KEYBOARD_LL` hook, **counter only** |
| Mouse activity | `WH_MOUSE_LL` hook — clicks, scrolls, pointer travel |
| Active application | `GetForegroundWindow` + process name |
| Active website | UI Automation read of the browser address bar (Chrome, Edge, Firefox, IE) |
| Time per app / per site | Foreground samples every N seconds, accumulated server-side |
| Screenshots | Scheduled at the admin's custom interval, plus manual and live-stream frames |

### On the keyboard hook

The hook **counts** key presses. It does not read, log, store or transmit what is
typed - no key codes, no characters, no text is ever captured or sent. It exists so
the report can show real keyboard activity instead of guessing. The same applies to
the mouse hook. If your policy requires the agent to be disabled entirely, switch
off *Record mouse & keyboard activity* in Agent Settings; the hooks are then never
installed.

## The panel

After signing in the agent shows a single centred card:

- **Live timer** - elapsed time for the current shift while on duty, otherwise
  today's total. Break time is subtracted.
- **Status dot + "Started at HH:MM"** - green on duty, amber on break, grey off duty.
- **Clock In / Clock Out** and **Take a Break / End Break**.
- **Break time left** - the daily allowance minus the break already taken, pushed
  from the server (`breakAllowanceMinutes` in Agent Settings, default 30).
- **Today** - the shift timeline (clock in, clock out, duration per segment) and
  the running total, from `GET /api/agent/day`.
- A muted footer line with the current app/site and the day's input counters.

Closing the window minimises to the notification area; the agent keeps recording
and can be reopened from the tray or with `--silent` at startup.

## Build

No Visual Studio or .NET SDK needed - it uses the C# compiler that ships with the
.NET Framework, present on every Windows machine.

```powershell
powershell -ExecutionPolicy Bypass -File agent\build-agent.ps1
```

Output: `agent\bin\WorkFolioAgent.exe` (a single ~60 KB file, no runtime install).

## Deploy

1. Copy `WorkFolioAgent.exe` to the employee's machine.
2. Run it and sign in with the work email and password. The server address is
   already set (default `https://folio.biztrack.uk`), which is baked into the EXE
   and can be overridden per build via `agent.config` - employees never see or
   type it. The retired host `workfolio.gt.tc` is rejected with an explicit
   error, because it only answers with a browser JavaScript challenge.
3. Optional: run `WorkFolioAgent.exe --install-autostart` to add it to Windows
   startup. Remove with `--uninstall-autostart`.

Command line flags:

| Flag | Effect |
|---|---|
| `--silent` / `--minimized` | Start hidden in the tray |
| `--install-autostart` | Add to `HKCU\...\Run` |
| `--uninstall-autostart` | Remove from `HKCU\...\Run` |

## Configuration

The server owns the policy (**Activity Report → Agent Settings**), and the agent
picks up changes within one sampling cycle, so no redeploy is needed:

- screenshot interval (10 s – 7200 s) and live-stream interval
- image quality and max width
- idle threshold
- app/website sampling interval
- per-employee switches for screenshots, input tracking, apps, websites
- excluded processes and blocked sites (privacy controls)

Machine-local values live in `agent.config` beside the EXE: server URL, last email,
minimise-on-start, autostart.

## Behaviour notes

- **One instance only.** A second launch shows a notice instead of double-recording.
- **Offline tolerant.** Usage samples and screenshots taken while the server is
  unreachable are queued under `%LOCALAPPDATA%\WorkFolioAgent` and replayed on
  reconnect, so a network drop does not lose recorded time.
- **Break aware.** No app or site time is recorded while on break.
- **Trapezoid accounting.** Time is credited to the window the employee was looking
  at *before* each tick, so alt-tabbing mid-interval is not misattributed.
- **Bounded samples.** A single sample can claim at most 120 s, so a rapidly
  flapping window cannot inflate a total.

## Files

| File | Responsibility |
|---|---|
| `Program.cs` | Entry point, single-instance mutex, session loop, autostart |
| `LoginForm.cs` | Sign-in (email + password), logo |
| `AgentIcons.cs` | Brand icon: EXE resource loader, tray + login logo |
| `MainForm.cs` | Control panel, tray icon, live totals |
| `MonitoringEngine.cs` | 1-second tick: sampling, idle, captures, retries |
| `ActiveWindowTracker.cs` | Foreground window → process / title / host |
| `BrowserUrlReader.cs` | UI Automation address-bar read (timeout-guarded) |
| `InputHooks.cs` | Low-level mouse and keyboard counters |
| `ScreenCapture.cs` | Multi-monitor capture, downscale, JPEG encode |
| `ApiClient.cs` | Server calls, auth, transport error handling |
| `OfflineQueue.cs` | Disk-backed retry buffer |
| `AgentSettings.cs` | Local config + server policy merge |
| `Native.cs` | Win32 P/Invoke declarations |

## Legal / policy

Monitoring software of this kind must be disclosed to staff and run within local
labour law. The exclusions and blocked-sites lists exist so personal and
confidential windows can be left unrecorded, and the input hooks deliberately
measure quantity rather than content.
