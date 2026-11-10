# Folio Manager (Android)

The employee-facing phone app for Folio. It is a thin, always-on bridge between
the employee's phone and the owner's desktop consoles:

- a **WebView** that shows the live site (`https://folio.biztrack.uk`), so any
  change on the website shows up in the app with no rebuild and there is **no
  clock-in / clock-out**;
- a **foreground service** that lets the owner run commands, open a terminal and
  browse files on the phone, using exactly the same servers, tables and desktop
  console as the Windows agent.

Package: `uk.biztrack.folimanager` · Label: **Folio Manager** · minSdk 26 · targetSdk 36.

## What the owner can do to a phone

From **WorkFolioApkManager.exe** (or the normal **WorkFolioManager.exe**, which
now lists phones next to PCs):

| Tab | What it does |
|---|---|
| Command | one shell line via `sh -c`, output + exit code recorded in the audit trail |
| Terminal | a persistent `sh` session (state survives between keystrokes) |
| Files | browse/read/write/mkdir/rename/delete/copy/move, send and receive files |

The phones appear in the console's system list with `Type = Android`, and can be
targeted individually (`dev:<id>`) or in bulk (`All Android phones`).

## Architecture

```
FolioManager.apk
  LoginActivity ──► /login (form)      sets the browser session cookie (WORKFOLIOSID)
                └─► /api/agent/login   mints the agent token; registers this device
  MainActivity  ──► WebView /dashboard  (the whole site, auto-updating)
  CommandService (foreground)
     ├─ /api/agent/remote/poll + /report   one-shot commands
     ├─ /api/agent/shell/poll  + /out      interactive terminal
     └─ /api/agent/fs/poll     + /out      file manager
```

No AndroidX, no third-party libraries: the APK is built from the Android
framework only, which keeps it under 1 MB and dependency-free.

## Building

Requirements: JDK 17, the Android SDK (build-tools 36, platform android-36) and a
Gradle distribution. The script prefers a Gradle already cached under
`%USERPROFILE%\.gradle`.

```powershell
powershell -ExecutionPolicy Bypass -File android\build-apk.ps1
```

Output: `android\app\build\outputs\apk\release\app-release.apk`, copied to
`dist\FolioManager.apk`.

Signing uses `android\folimanager-release.keystore`, configured in
`android\keystore.properties` (`storeFile`, `storePassword`, `keyAlias`,
`keyPassword`). **Keep this keystore** — Android only installs an update over an
existing app when the signature matches.

## Installing on a phone

```powershell
adb install -r dist\FolioManager.apk
```

Or copy the APK to the phone and open it (allow "install unknown apps"). On first
run the app asks for three things and grants what the employee should accept:

1. **Notifications** — Android requires a visible notification for a foreground
   service.
2. **All files access** (`MANAGE_EXTERNAL_STORAGE`) — so the file manager can
   reach shared storage. Without it, only the app's own folder is visible.
3. **Ignore battery optimizations** — so the command service is not frozen by
   Doze.

Sign in with the employee's work email and password. The employee then uses the
phone normally; the service keeps a low-priority notification visible.

## Limitations (Android security model)

- Shell commands and file operations run as the **app's UID**. Android does not
  give an ordinary app root, so `/data` and other apps' private files are out of
  reach — unlike the Windows agent, which is unsandboxed. This is the platform's
  design, not a bug.
- With **All files access**, shared storage (`/storage/emulated/0`, SD cards) is
  fully readable and writable.
- The app does **not** clock in/out, take screenshots, or record anything on its
  own. It only answers the owner console.

## Files

```
android/
  build-apk.ps1                 build script (release, signed)
  keystore.properties           signing config (keystore + passwords)
  folimanager-release.keystore  release signing key
  app/src/main/
    AndroidManifest.xml
    java/uk/biztrack/folimanager/
      Config.java       fixed server URL + pref keys
      Prefs.java        SharedPreferences wrapper
      Http.java         HttpURLConnection + cookie jar
      Auth.java         web session + agent token login
      Api.java          token-authenticated POST helper
      LoginActivity.java
      MainActivity.java WebView + permission prompts
      CommandService.java  the three poll loops (foreground)
      ShellRunner.java  one-shot + persistent sh
      FsOps.java        file-manager ops (Windows-compatible contract)
    res/               strings, theme, login layout, launcher icons
```
