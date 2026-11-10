using System;
using System.Diagnostics;
using System.Text.RegularExpressions;

namespace WorkFolio.Agent
{
    /// <summary>One observed moment: what app, which window, which website.</summary>
    internal sealed class WindowSample
    {
        public string ProcessName = "unknown";
        public string Title = string.Empty;
        public string Host = string.Empty;
        public string DisplayName = string.Empty;
        public string TargetKey = "app:unknown";
        public string TargetType = "APP";
        public bool IsBrowser;
        public bool IsIdle;
    }

    /// <summary>
    /// Reads the foreground window: process name, title and - for browsers - the
    /// address currently shown, so the report can say "github.com for 1h 12m"
    /// instead of just "chrome.exe".
    /// </summary>
    internal sealed class ActiveWindowTracker
    {
        private static readonly Regex UrlInTitle = new Regex(@"https?://[^\s]+", RegexOptions.IgnoreCase);
        private static readonly Regex ChromeLike = new Regex(
            @"^(chrome|msedge|brave|opera|vivaldi|chromium|edge|safari|firefox|iexplore)\.exe$",
            RegexOptions.IgnoreCase);

        private readonly BrowserUrlReader _urlReader = new BrowserUrlReader();

        /// <summary>Cached per window handle; probing the URL bar is the expensive part.</summary>
        private IntPtr _lastWindow = IntPtr.Zero;
        private WindowSample _cached = new WindowSample();
        private long _cachedAt;

        public WindowSample Current(bool idle, bool trackWebsites)
        {
            IntPtr hwnd = Native.GetForegroundWindow();
            if (hwnd == IntPtr.Zero || hwnd == _lastWindow)
            {
                if (hwnd == _lastWindow && _cachedAt > 0) return _cached;
            }

            WindowSample sample = new WindowSample();
            sample.IsIdle = idle;

            try
            {
                uint pid;
                Native.GetWindowThreadProcessId(hwnd, out pid);
                string process = "unknown";
                try
                {
                    using (Process p = Process.GetProcessById((int)pid))
                    {
                        process = p.ProcessName;
                    }
                }
                catch (Exception) { /* process exited between calls */ }

                sample.ProcessName = process + ".exe";
                sample.Title = Native.GetWindowTitleSafe(hwnd);
                sample.IsBrowser = ChromeLike.IsMatch(sample.ProcessName);

                if (idle)
                {
                    sample.DisplayName = "Idle";
                    sample.TargetKey = "idle";
                    sample.TargetType = "IDLE";
                }
                else if (sample.IsBrowser)
                {
                    string host = trackWebsites ? _urlReader.ReadHost(hwnd) : null;
                    if (string.IsNullOrEmpty(host))
                    {
                        // Address bar unavailable - fall back to a host hinted in the title.
                        Match m = UrlInTitle.Match(sample.Title);
                        if (m.Success)
                        {
                            try { host = new Uri(m.Value).Host; } catch (Exception) { host = null; }
                        }
                    }
                    if (!string.IsNullOrEmpty(host))
                    {
                        sample.Host = host;
                        sample.DisplayName = host;
                        sample.TargetKey = "web:" + host.ToLowerInvariant();
                        sample.TargetType = "WEB";
                    }
                    else
                    {
                        sample.DisplayName = FriendlyName(sample.ProcessName);
                        sample.TargetKey = "app:" + sample.ProcessName.ToLowerInvariant();
                        sample.TargetType = "APP";
                    }
                }
                else
                {
                    sample.DisplayName = FriendlyName(sample.ProcessName);
                    sample.TargetKey = "app:" + sample.ProcessName.ToLowerInvariant();
                    sample.TargetType = "APP";
                }
            }
            catch (Exception ex)
            {
                Trace.WriteLine("ActiveWindowTracker.Current failed: " + ex.Message);
                sample.DisplayName = "unknown";
                sample.TargetKey = "app:unknown";
            }

            _lastWindow = hwnd;
            _cached = sample;
            _cachedAt = DateTime.UtcNow.Ticks;
            return sample;
        }

        /// <summary>"chrome.exe" -> "Google Chrome", so the report reads well.</summary>
        public static string FriendlyName(string processName)
        {
            if (string.IsNullOrEmpty(processName)) return "Unknown";
            switch (processName.ToLowerInvariant())
            {
                case "chrome.exe": return "Google Chrome";
                case "msedge.exe": return "Microsoft Edge";
                case "firefox.exe": return "Mozilla Firefox";
                case "iexplore.exe": return "Internet Explorer";
                case "brave.exe": return "Brave";
                case "opera.exe": return "Opera";
                case "code.exe": return "VS Code";
                case "devenv.exe": return "Visual Studio";
                case "idea64.exe": return "IntelliJ IDEA";
                case "pycharm64.exe": return "PyCharm";
                case "webstorm64.exe": return "WebStorm";
                case "notepad++.exe": return "Notepad++";
                case "winword.exe": return "MS Word";
                case "excel.exe": return "MS Excel";
                case "powerpnt.exe": return "MS PowerPoint";
                case "outlook.exe": return "MS Outlook";
                case "onenote.exe": return "MS OneNote";
                case "slack.exe": return "Slack";
                case "teams.exe": return "MS Teams";
                case "zoom.exe": return "Zoom";
                case "spotify.exe": return "Spotify";
                case "postman.exe": return "Postman";
                case "dbeaver.exe": return "DBeaver";
                case "postgreql.exe": return "pgAdmin";
                case "mongodb.exe": return "MongoDB Shell";
                case "sqlservr.exe": return "SQL Server";
                case "explorer.exe": return "File Explorer";
                case "cmd.exe": return "Command Prompt";
                case "powershell.exe": return "PowerShell";
                case "windowsterminal.exe": return "Windows Terminal";
                case "javaw.exe": return "Java Application";
                case "workfolioagente.exe": return "Folio Agent";
            }

            string bare = processName;
            if (bare.EndsWith(".exe", StringComparison.OrdinalIgnoreCase))
            {
                bare = bare.Substring(0, bare.Length - 4);
            }
            if (bare.Length == 0) return "Unknown";
            return char.ToUpperInvariant(bare[0]) + bare.Substring(1);
        }
    }
}
