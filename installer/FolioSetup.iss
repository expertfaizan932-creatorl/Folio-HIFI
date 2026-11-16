; ============================================================================
;  Folio Agent - Windows installer
;
;  Produces a single setup.exe that puts the agent into a proper environment:
;  the runtimes it needs are installed if missing, shortcuts are created, the
;  agent registers itself to start with Windows, and uninstalling removes the
;  program files and the startup entry again.
;
;  Uninstalling deliberately LEAVES the local shift ledger in
;  %LOCALAPPDATA%\WorkFolioAgent. That file holds the current shift, including
;  hours that have not reached the server yet, so deleting it on uninstall would
;  throw away real attendance. Removing the program does not remove the data.
;
;  The runtime installers are downloaded once at build time by build-setup.ps1
;  into installer\payload\ and embedded in this file, so setup.exe is
;  self-contained and works on a machine with no internet access. Each is only
;  installed when the Check function below finds it missing, so a normal install
;  on a current Windows box does nothing at all.
;
;  Build:  powershell -ExecutionPolicy Bypass -File build-setup.ps1
; ============================================================================

#define AppName        "Folio Agent"
#define AppVersion     "1.4.1"
#define AppVersionFull "1.4.1.0"
#define AppPublisher   "Hifi Marketing"
#define AppId          "{{7C4B9E10-3F52-4A6B-9C21-8D5E6F0A1B34}"
#define AppExe         "WorkFolioAgent.exe"
#define PayloadDir     "..\installer\payload"

[Setup]
AppId={#AppId}
AppName={#AppName}
AppVersion={#AppVersion}
AppVerName={#AppName} {#AppVersion}
VersionInfoVersion={#AppVersionFull}
VersionInfoDescription={#AppName} installer
AppPublisher={#AppPublisher}
DefaultDirName={localappdata}\Programs\{#AppName}
DefaultGroupName={#AppName}
DisableProgramGroupPage=yes
OutputDir=..\dist
OutputBaseFilename=FolioSetup
Compression=lzma2/max
SolidCompression=yes
WizardStyle=modern
; Per-user on purpose. The agent installs under the employee's own profile and
; needs no administrator rights, so the setup runs cleanly on a standard,
; locked-down account with no UAC. That also lets the agent replace its own EXE
; when a new version is published - a machine-wide install in Program Files
; could not be updated without an administrator every single time.
PrivilegesRequired=lowest
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0
CloseApplications=yes
; Only ever the agent. A wildcard here makes Restart Manager treat every
; running .exe as a candidate and offer to close the employee's browser and
; document for no reason.
CloseApplicationsFilter=WorkFolioAgent.exe
RestartApplications=no
SetupIconFile=..\agent\assets\app.ico
UninstallDisplayIcon={app}\{#AppExe}
LicenseFile=..\installer\LICENSE.txt
DisableDirPage=no

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "runtimecheck"; Description: "Install any missing runtimes {#AppName} needs"; GroupDescription: "Prerequisites"
Name: "autostart";    Description: "Start {#AppName} when I sign in to Windows"; GroupDescription: "Startup"
Name: "desktopicon";  Description: "{cm:CreateDesktopIcon}"; GroupDescription: "{cm:AdditionalIcons}"

[Files]
Source: "..\agent\bin\{#AppExe}"; DestDir: "{app}"; Flags: ignoreversion
; The agent window is HTML rendered by WebView2 (Edge), so these three files
; must sit beside the EXE. The two managed assemblies and the native loader are
; part of the WebView2 SDK and are redistributed under Microsoft's terms.
Source: "..\agent\bin\Microsoft.Web.WebView2.Core.dll"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\agent\bin\Microsoft.Web.WebView2.WinForms.dll"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\agent\bin\WebView2Loader.dll"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\agent\assets\README.txt"; DestDir: "{app}"; Flags: ignoreversion
; Blank on purpose. This file is copied to every machine, so a remembered
; address here is how one employee's email ends up pre-filled on somebody
; else's login screen.
Source: "..\agent\assets\agent.config.template"; DestDir: "{app}"; DestName: "agent.config"; Flags: onlyifdoesntexist

; Runtime payload. Only laid down when the Check function says it is missing,
; and only installed when the user left the prerequisite task ticked.
;
; The .NET Framework 4.8 runtime is deliberately NOT bundled. It is an OS
; component of Windows 10 1809 and Windows 11, so on every machine this ships to
; it is already present, and the offline installer alone would add ~140 MB to
; every USB stick for insurance nobody needs. If it really is missing the
; installer says so and points at the official download instead.
Source: "{#PayloadDir}\vc_redist.x64.exe"; DestDir: "{tmp}"; Flags: deleteafterinstall; Check: VCppMissing

[Icons]
Name: "{group}\{#AppName}"; Filename: "{app}\{#AppExe}"
Name: "{group}\Getting started"; Filename: "{app}\README.txt"
Name: "{group}\{cm:UninstallProgram,{#AppName}}"; Filename: "{uninstallexe}"
Name: "{autodesktop}\{#AppName}"; Filename: "{app}\{#AppExe}"; Tasks: desktopicon

[Run]
; Registered through the agent's own flag, so the Run entry is written exactly
; the way the app writes it and cannot drift from it.
Filename: "{app}\{#AppExe}"; Parameters: "--install-autostart"; Flags: runhidden waituntilterminated; Tasks: autostart
Filename: "{tmp}\vc_redist.x64.exe"; Parameters: "/install /quiet /norestart"; Flags: runhidden waituntilterminated; Tasks: runtimecheck; Check: VCppMissing
Filename: "{app}\{#AppExe}"; Parameters: "--silent"; Flags: nowait postinstall skipifsilent

[UninstallRun]
; Drop the agent out of the notification area before its files are removed,
; otherwise the process is still running when the files go.
Filename: "{app}\{#AppExe}"; Parameters: "--uninstall-autostart"; Flags: runhidden waituntilterminated; RunOnceId: "RemoveAutoStart"

[Code]
{ ---------------------------------------------------------------------------
  Prerequisite detection.

  The agent is managed C# on .NET Framework 4.x. On Windows 10 1809 and Windows
  11 it ships with the OS, so on almost every machine both Checks return False
  and neither payload is copied or run - the install is then just the agent.

  The Visual C++ redistributable is not a hard dependency: the agent only
  P/Invokes into user32, which needs no C++ runtime. It is bundled because some
  machines also run other Folio components that do need it, and shipping it
  costs the employee one click instead of a separate IT visit.
  --------------------------------------------------------------------------- }

function DotNet48Installed: Boolean;
var
  Release: Cardinal;
begin
  Result := False;
  if RegQueryDWordValue(HKLM, 'SOFTWARE\Microsoft\NET Framework Setup\NDP\v4\Full',
                        'Release', Release) then
  begin
    { 528040 = .NET Framework 4.8, shipped with the May 2019 Windows 10 update }
    Result := Release >= 528040;
    if not Result then
      Log('.NET Framework Release=' + IntToStr(Release) + ', older than 4.8');
  end
  else
  if RegQueryDWordValue(HKLM, 'SOFTWARE\WOW6432Node\Microsoft\NET Framework Setup\NDP\v4\Full',
                        'Release', Release) then
    Result := Release >= 528040;
end;

function DotNetMissing: Boolean;
begin
  Result := not DotNet48Installed;
end;

function VCppInstalled: Boolean;
begin
  Result := RegKeyExists(HKLM, 'SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64');
  if not Result then
    Result := RegKeyExists(HKLM, 'SOFTWARE\WOW6432Node\Microsoft\VisualStudio\14.0\VC\Runtimes\x64');
end;

function VCppMissing: Boolean;
begin
  Result := not VCppInstalled;
end;

procedure InitializeWizard();
begin
  if DotNetMissing then
  begin
    { The agent will not start without this. Say it plainly on the finish page
      rather than shipping 140 MB of installer for a machine that almost
      certainly does not need it. }
    Log('.NET Framework 4.8 not present - the agent will not start until it is installed.')
  end
  else
    Log('.NET Framework 4.8 already present.');

  if VCppMissing then
    Log('Visual C++ redistributable not found - the bundled installer will add it.')
  else
    Log('Visual C++ redistributable already present.');
end;

procedure CurPageChanged(CurPageID: Integer);
begin
  { Warn on the last page, where the employee has already committed to the
    install, rather than adding another wizard page. }
  if (CurPageID = wpFinished) and DotNetMissing then
    MsgBox('One thing is still missing:' + #13#10 + #13#10 +
           'This computer does not have the Microsoft .NET Framework 4.8 that ' +
           'Folio Agent needs, so the agent will not start until it is installed.' +
           + #13#10#13#10 +
           'Folio Agent itself is installed and ready. Microsoft''s official ' +
           'offline installer is one click away:' + #13#10#13#10 +
           'https://go.microsoft.com/fwlink/?linkid=2088517' + #13#10#13#10 +
           'It needs an internet connection to fetch, takes about ten minutes, ' +
           'and restarts the computer when it finishes.',
           mbInformation, MB_OK);
end;



