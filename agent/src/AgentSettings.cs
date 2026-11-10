using System;
using System.Collections.Generic;
using System.IO;
using System.Text;
using System.Web.Script.Serialization;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Server-supplied monitoring policy plus the few machine-local values
    /// (server address, remembered login). Kept in agent.config next to the EXE.
    /// </summary>
    internal sealed class AgentSettings
    {
        // ---- machine local ----
        public string ServerUrl = "https://folio.biztrack.uk";
        public string LastEmail = string.Empty;
        public bool StartWithWindows = false;
        public bool StartMinimized = false;

        // ---- pushed from the server on login and on every cycle ----
        public int ScreenshotIntervalSeconds = 300;
        public int LiveStreamIntervalSeconds = 0;
        /// <summary>
        /// Live frame cadence in milliseconds. The server pushes this (120ms per
        /// frame by default, about 8 fps); LiveStreamIntervalSeconds is only its
        /// rounded-up mirror, kept for servers and templates that predate it.
        /// </summary>
        public int LiveStreamIntervalMs = 120;
        public int IdleThresholdSeconds = 180;
        public int ImageQuality = 55;
        public int ImageMaxWidth = 1280;
        public int SampleIntervalSeconds = 10;
        public int BreakAllowanceMinutes = 30;
        public bool CaptureScreenshots = true;
        public bool TrackMouseKeyboard = true;
        public bool TrackAppUsage = true;
        public bool TrackWebsites = true;
        public string ExcludedProcesses = string.Empty;
        public string BlockedSites = string.Empty;

        private static readonly string FileName = "agent.config";

        /// <summary>
        /// Read-only template shipped next to the executable. build-usb.ps1 writes
        /// it so a portable copy already knows the server URL. It is never written
        /// to, otherwise running from a USB stick would stamp the signed-in
        /// employee's email onto the shared package.
        /// </summary>
        public static string TemplatePath
        {
            get { return Path.Combine(AppDomain.CurrentDomain.BaseDirectory, FileName); }
        }

        /// <summary>
        /// The mutable copy, always per-user for the same reasons as
        /// <see cref="StatePathFor"/>. Saving next to the .exe leaked the signed-in
        /// email into the USB package and made two machines that share the stick
        /// overwrite each other's settings.
        /// </summary>
        public static string FilePath
        {
            get { return StatePathFor(FileName); }
        }

        /// <summary>
        /// Path for a companion state file. The shift ledger uses this so a crash
        /// or restart does not lose worked time.
        ///
        /// Prefer a per-user folder over the folder holding the executable: the
        /// agent also runs from Program Files and from a USB stick, where writing
        /// next to the .exe either fails outright or silently loses state on the
        /// next upgrade. A per-user path also survives the executable being
        /// replaced, and keeps two USB sticks on one machine from sharing a shift.
        /// </summary>
        public static string StatePathFor(string fileName)
        {
            string userDir = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "WorkFolioAgent");
            try
            {
                if (!Directory.Exists(userDir)) Directory.CreateDirectory(userDir);
                // Confirm it is actually writable before committing to it.
                string probe = Path.Combine(userDir, ".writable");
                File.WriteAllText(probe, "1");
                File.Delete(probe);
                return Path.Combine(userDir, fileName);
            }
            catch (Exception)
            {
                return Path.Combine(AppDomain.CurrentDomain.BaseDirectory, fileName);
            }
        }

        [ScriptIgnore]
        public string[] ExcludedProcessList
        {
            get { return SplitCsv(ExcludedProcesses); }
        }

        [ScriptIgnore]
        public string[] BlockedSiteList
        {
            get { return SplitCsv(BlockedSites); }
        }

        private static string[] SplitCsv(string csv)
        {
            if (string.IsNullOrEmpty(csv)) return new string[0];
            string[] parts = csv.Split(',');
            int count = 0;
            foreach (string p in parts)
            {
                if (!string.IsNullOrEmpty(p.Trim())) count++;
            }
            string[] result = new string[count];
            int i = 0;
            foreach (string p in parts)
            {
                string t = p.Trim();
                if (t.Length > 0) result[i++] = t.ToLowerInvariant();
            }
            return result;
        }

        public bool IsProcessExcluded(string processName)
        {
            return Matches(processName, ExcludedProcessList, true);
        }

        public bool IsSiteBlocked(string host)
        {
            return Matches(host, BlockedSiteList, false);
        }

        private static bool Matches(string value, string[] list, bool compareWithExtension)
        {
            if (string.IsNullOrEmpty(value) || list.Length == 0) return false;
            string v = value.Trim().ToLowerInvariant();
            if (compareWithExtension && !v.EndsWith(".exe")) v = v + ".exe";
            foreach (string item in list)
            {
                if (v == item) return true;
                if (v.EndsWith("." + item) || v.StartsWith(item + ".")) return true;
                if (!compareWithExtension && v.EndsWith("." + item)) return true;
            }
            return false;
        }

        /// <summary>Copies server values over the local ones, leaving machine-local fields alone.</summary>
        public void ApplyServerConfig(object serverConfig)
        {
            if (serverConfig == null) return;
            Dictionary<string, object> map = ToMap(serverConfig);
            if (map == null || map.Count == 0) return;

            ScreenshotIntervalSeconds = ReadInt(map, "screenshotIntervalSeconds", ScreenshotIntervalSeconds, 10, 7200);
            LiveStreamIntervalSeconds = ReadInt(map, "liveStreamIntervalSeconds", LiveStreamIntervalSeconds, 0, 600);
            // The millisecond value is authoritative when present; a server that
            // only sends whole seconds is converted, so an upgrade cannot turn a
            // configured stream off (0 seconds must still mean 0, i.e. off).
            LiveStreamIntervalMs = map.ContainsKey("liveStreamIntervalMs")
                ? ReadInt(map, "liveStreamIntervalMs", LiveStreamIntervalMs, 0, 30000)
                : LiveStreamIntervalSeconds * 1000;
            IdleThresholdSeconds = ReadInt(map, "idleThresholdSeconds", IdleThresholdSeconds, 10, 3600);
            ImageQuality = ReadInt(map, "imageQuality", ImageQuality, 10, 100);
            ImageMaxWidth = ReadInt(map, "imageMaxWidth", ImageMaxWidth, 320, 3840);
            SampleIntervalSeconds = ReadInt(map, "sampleIntervalSeconds", SampleIntervalSeconds, 2, 300);
            BreakAllowanceMinutes = ReadInt(map, "breakAllowanceMinutes", BreakAllowanceMinutes, 0, 480);
            CaptureScreenshots = ReadBool(map, "captureScreenshots", CaptureScreenshots);
            TrackMouseKeyboard = ReadBool(map, "trackMouseKeyboard", TrackMouseKeyboard);
            TrackAppUsage = ReadBool(map, "trackAppUsage", TrackAppUsage);
            TrackWebsites = ReadBool(map, "trackWebsites", TrackWebsites);

            object excluded = Find(map, "excludedProcesses");
            if (excluded != null) ExcludedProcesses = excluded.ToString();
            object blocked = Find(map, "blockedSites");
            if (blocked != null) BlockedSites = blocked.ToString();
        }

        private static object Find(Dictionary<string, object> map, string key)
        {
            return map.ContainsKey(key) ? map[key] : null;
        }

        private static int ReadInt(Dictionary<string, object> map, string key, int fallback, int min, int max)
        {
            object raw = Find(map, key);
            if (raw == null) return fallback;
            try
            {
                int value = Convert.ToInt32(raw);
                // A key that is present and set to 0 is a deliberate "off" from
                // admin policy and must be honoured. Treating it as "not supplied"
                // left the agent streaming even after an admin turned it off.
                return Math.Max(min, Math.Min(max, value));
            }
            catch (Exception)
            {
                return fallback;
            }
        }

        private static bool ReadBool(Dictionary<string, object> map, string key, bool fallback)
        {
            object raw = Find(map, key);
            if (raw == null) return fallback;
            try
            {
                if (raw is bool) return (bool)raw;
                return Convert.ToBoolean(raw);
            }
            catch (Exception)
            {
                return fallback;
            }
        }

        private static Dictionary<string, object> ToMap(object value)
        {
            if (value is Dictionary<string, object>) return (Dictionary<string, object>)value;
            if (value == null) return null;

            // JavaScriptSerializer hands back Dictionary<string,object>; if the caller
            // already deserialised it, round-trip through JSON to get the same shape.
            try
            {
                JavaScriptSerializer serializer = new JavaScriptSerializer();
                object parsed = serializer.DeserializeObject(new JavaScriptSerializer().Serialize(value));
                return parsed as Dictionary<string, object>;
            }
            catch (Exception)
            {
                return null;
            }
        }

        /// <summary>
        /// The address the agent should actually use: the configured one, or the
        /// current server when the config is blank or still points at a host we
        /// retired. A pure string decision so it can be tested without touching
        /// disk; <see cref="Load"/> applies it and saves only when it changes.
        /// </summary>
        public static string HealServerUrl(string url)
        {
            if (string.IsNullOrEmpty(url)) return ApiClient.DefaultServerUrl;
            string trimmed = url.Trim();
            if (trimmed.Length == 0) return ApiClient.DefaultServerUrl;
            if (ApiClient.IsRetiredServerUrl(trimmed)) return ApiClient.DefaultServerUrl;
            return trimmed;
        }

        public void Save()
        {
            try
            {
                // Never write the signed-in address into the shipped template. It
                // is the file copied to every machine, so one employee remembered
                // here becomes the next employee's pre-filled login - which is how
                // a colleague's address ended up baked into a USB package.
                if (string.Equals(FilePath, TemplatePath, StringComparison.OrdinalIgnoreCase))
                {
                    LastEmail = string.Empty;
                }

                JavaScriptSerializer serializer = new JavaScriptSerializer();
                string json = serializer.Serialize(this);
                File.WriteAllText(FilePath, json, Encoding.UTF8);
            }
            catch (Exception)
            {
                // A read-only install folder is not a reason to stop monitoring.
            }
        }

        public static AgentSettings Load()
        {
            AgentSettings settings;
            try
            {
                // Per-user copy wins; otherwise seed from the shipped template next
                // to the executable and copy it across so later saves stay per-user.
                if (!File.Exists(FilePath))
                {
                    settings = new AgentSettings();
                    if (File.Exists(TemplatePath))
                    {
                        try
                        {
                            string seedJson = File.ReadAllText(TemplatePath, Encoding.UTF8);
                            JavaScriptSerializer seedSerializer = new JavaScriptSerializer();
                            settings = seedSerializer.Deserialize<AgentSettings>(seedJson) ?? new AgentSettings();
                        }
                        catch (Exception)
                        {
                        }
                    }
                    settings.Save();
                }
                else
                {
                    string json = File.ReadAllText(FilePath, Encoding.UTF8);
                    JavaScriptSerializer serializer = new JavaScriptSerializer();
                    settings = serializer.Deserialize<AgentSettings>(json) ?? new AgentSettings();
                }
            }
            catch (Exception)
            {
                settings = new AgentSettings();
            }

            // Repair a stale or missing address so a machine that still carries an
            // old agent.config - one written before the move to the current server
            // - rejoins on its own instead of sitting on a host we retired. Only
            // written when it actually changes, so a correct config is never
            // rewritten; that matters because this file sits beside the shift
            // ledger and is read on every launch.
            string healed = HealServerUrl(settings.ServerUrl);
            if (!string.Equals(healed, settings.ServerUrl, StringComparison.Ordinal))
            {
                settings.ServerUrl = healed;
                settings.Save();
            }

            return settings;
        }
    }
}
