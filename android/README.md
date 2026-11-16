# Folio Manager (Android)

The employee-facing phone app for Folio. It is a thin, always-on bridge between
the employee's phone and the web console:

- a **WebView** that shows the live site, so any change on the website shows up
  in the app with no rebuild and there is **no clock-in / clock-out**;
- a **foreground service** that keeps the session alive while the employee uses
  the phone for anything else.

Package: `uk.biztrack.folimanager` · Label: **Folio Manager** · minSdk 26 · targetSdk 36.

> **Note:** this build keeps only the WebView and the foreground notification;
> the service performs no remote operations of any kind.

## Architecture

```
FolioManager.apk
  LoginActivity ──► /login (form)      sets the browser session cookie (WORKFOLIOSID)
                └─► /api/agent/login   mints the agent token; registers this device
  MainActivity  ──► WebView /dashboard  (the whole site, auto-updating)
  CommandService (foreground notification only)
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
2. **All files access** (`MANAGE_EXTERNAL_STORAGE`) — so the WebView can open
   files the employee picks through the normal Android file chooser.
3. **Ignore battery optimizations** — so the service is not frozen by Doze.

Sign in with the employee's work email and password. The employee then uses the
phone normally; the service keeps a low-priority notification visible.

## Limitations (Android security model)

- The app does **not** clock in/out, take screenshots, run shell commands, read
  or write files, or record anything on its own. It only renders the site and
  keeps the session alive.

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
      CommandService.java  foreground notification service
    res/               strings, theme, login layout, launcher icons
```
